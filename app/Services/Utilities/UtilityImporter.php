<?php

namespace App\Services\Utilities;

use App\Enums\ChargeType;
use App\Enums\LedgerAccount;
use App\Enums\LedgerKind;
use App\Models\Concerns\WorksheetRules;
use App\Models\ImportBatch;
use App\Models\PaidUtilityBill;
use App\Models\PaidUtilityBillPart;
use App\Models\Provider;
use App\Models\Scopes\CompanyScope;
use App\Models\Scopes\RegistryVisibilityScope;
use App\Models\UtilityAccount;
use App\Models\UtilityImportIssue;
use App\Services\Charges\Cents;
use App\Services\Charges\Ledger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Внос на платените битови сметки (PROJECT.md, т. 5.3 „Внос на платените
 * сметки“, „Внос, уточнения“, „Внос, допълнения“, 21–22.11.2026).
 *
 * - абонатът се търси по доставчик (името във файла) + номер, без значение
 *   от нулите отпред; общата сметка се дели по съотношението, остатъкът
 *   (от закръгляне или сбор под 100 %) отива към последния имот;
 * - всяка част е запис по 407 за имота, в месеца на плащане;
 * - двоен внос: номерът на превода (EasyPay) или системният код (ePay) се
 *   приема веднъж във фирмата (и в базата); вносът заключва фирмата;
 * - всичко неразпознато е „за решение“; приходите в ePay се пропускат тихо,
 *   банковите такси се пропускат и само се броят;
 * - само служител с „четене и запис“ и „всички имоти“.
 */
final class UtilityImporter
{
    public function __construct(private readonly UtilityFileReader $reader) {}

    /** Какво ще се внесе. Нищо не се записва. */
    public function preview(string $path): ImportPreview
    {
        [$source, $rows] = $this->reader->read($path);

        return $this->classify($source, $rows);
    }

    /** Внася файла (всичко или нищо). Файлът не се пази: пазят се само редовете. */
    public function import(string $path, string $fileName): ImportBatch
    {
        $this->ensureCanManage();
        [$source, $rows] = $this->reader->read($path);

        return DB::transaction(function () use ($source, $rows, $fileName) {
            Ledger::lockCompany($this->companyId());
            $preview = $this->classify($source, $rows); // наново, след заключването

            $batch = ImportBatch::query()->create([
                'source' => $source,
                'file_name' => mb_substr($fileName, 0, 255),
                'rows_count' => $preview->rowsCount,
                'bills_count' => count($preview->bills),
                'duplicates' => count($preview->duplicates),
                'for_decision' => count($preview->issues),
                'skipped_fees' => $preview->skippedFees,
                'imported_by' => Auth::id(),
            ]);

            foreach ($preview->bills as $bill) {
                $this->book($batch->id, $source, $bill);
            }

            foreach ($preview->issues as $issue) {
                $row = $issue->row;
                UtilityImportIssue::query()->create([
                    'import_batch_id' => $batch->id, 'source' => $source, 'reference' => $row->reference,
                    'paid_at' => $row->paidAt, 'amount' => $row->amountCents === null ? null : Cents::format($row->amountCents),
                    'provider_name' => $row->providerName, 'provider_id' => $issue->providerId, 'number' => $row->number,
                    'description' => $row->description === null ? null : mb_substr($row->description, 0, 500), 'details' => $row->details,
                    'reason' => $issue->reason, 'status' => UtilityImportIssue::OPEN,
                ]);
            }

            return $batch;
        });
    }

    /**
     * Опитва отново отворените редове „за решение“ (например след нов абонат
     * или свързан доставчик). Връща колко са внесени.
     */
    public function retryOpen(): int
    {
        $this->ensureCanManage();

        return DB::transaction(function () {
            Ledger::lockCompany($this->companyId());
            $imported = 0;

            foreach (UtilityImportIssue::query()->where('status', UtilityImportIssue::OPEN)->orderBy('id')->get() as $issue) {
                $row = new ImportRow(0, 'bill', $issue->reference, $issue->paid_at, $issue->provider_name, $issue->number,
                    $issue->amount === null ? null : Cents::from($issue->amount), $issue->description, $issue->details);

                if ($row->reference !== null && $this->billExists($issue->source, $row->reference)) {
                    continue; // вече е внесен по друг път
                }

                $result = $this->recognise($issue->source, $row);
                if ($result instanceof PreviewBill) {
                    $bill = $this->book($issue->import_batch_id, $issue->source, $result);
                    $issue->update([
                        'status' => UtilityImportIssue::IMPORTED, 'paid_utility_bill_id' => $bill->id,
                        'decided_by' => Auth::id(), 'decided_at' => now(),
                    ]);
                    $imported++;
                } elseif ($result->reason !== $issue->reason || $result->providerId !== $issue->provider_id) {
                    $issue->update(['reason' => $result->reason, 'provider_id' => $result->providerId]);
                }
            }

            return $imported;
        });
    }

    /** „Игнорирай“ с причина: редът се помни и не излиза пак. */
    public function ignore(UtilityImportIssue $issue, ?string $reason): void
    {
        $this->ensureCanManage();
        $reason = trim((string) $reason);

        if ($reason === '' || mb_strlen($reason) > 500) {
            throw ValidationException::withMessages(['reason' => __('utilities.import.errors.ignore_reason')]);
        }
        if ($issue->status !== UtilityImportIssue::OPEN) {
            throw ValidationException::withMessages(['reason' => __('utilities.import.errors.not_open')]);
        }

        $issue->update([
            'status' => UtilityImportIssue::IGNORED, 'ignore_reason' => $reason,
            'decided_by' => Auth::id(), 'decided_at' => now(),
        ]);
    }

    /**
     * „Премахни игнорирането“ (решено 22.11.2026): правилото се изтрива, със
     * запис в журнала, и при следващия внос редът се чете отново като нов.
     */
    public function unignore(UtilityImportIssue $issue): void
    {
        $this->ensureCanManage();

        if ($issue->status !== UtilityImportIssue::IGNORED) {
            throw ValidationException::withMessages(['issue' => __('utilities.import.errors.not_ignored')]);
        }

        DB::transaction(function () use ($issue) {
            Ledger::lockCompany($this->companyId());
            $issue->delete();
        });
    }

    /**
     * Непознато име на доставчик във файла: записва се при избрания доставчик
     * (ако той още няма име за този файл) и редовете се опитват отново.
     */
    public function linkProvider(UtilityImportIssue $issue, Provider $provider): int
    {
        $this->ensureCanManage();
        $column = Provider::FILE_COLUMNS[$issue->source] ?? throw new LogicException('Непознат източник.');

        if ($issue->status !== UtilityImportIssue::OPEN || $issue->reason !== 'unknown_provider' || $issue->provider_name === null) {
            throw ValidationException::withMessages(['provider_id' => __('utilities.import.errors.not_open')]);
        }
        if ($provider->{$column} !== null && $provider->{$column} !== $issue->provider_name) {
            throw ValidationException::withMessages(['provider_id' => __('utilities.import.errors.provider_has_name', ['name' => $provider->{$column}])]);
        }

        return DB::transaction(function () use ($provider, $column, $issue) {
            $provider->update([$column => $issue->provider_name]);

            return $this->retryOpen();
        });
    }

    // Разпознаване

    /** @param list<ImportRow> $rows */
    private function classify(string $source, array $rows): ImportPreview
    {
        $references = array_values(array_filter(array_map(fn (ImportRow $row) => $row->reference, $rows)));
        $known = array_flip(array_merge(
            PaidUtilityBill::query()->withoutGlobalScope(RegistryVisibilityScope::class)
                ->where('source', $source)->whereIn('reference', $references)->pluck('reference')->all(),
            UtilityImportIssue::query()->withoutGlobalScope(RegistryVisibilityScope::class)
                ->where('source', $source)->whereIn('reference', $references)->pluck('reference')->all(),
        ));

        $bills = $duplicates = $issues = [];
        $fees = 0;
        $seen = [];

        foreach ($rows as $row) {
            if ($row->kind === 'income') {
                continue;
            }
            if ($row->kind === 'fee') {
                $fees++;

                continue;
            }
            if ($row->reference !== null && (isset($known[$row->reference]) || isset($seen[$row->reference]))) {
                $duplicates[] = $row;

                continue;
            }
            if ($row->reference !== null) {
                $seen[$row->reference] = true;
            }

            $result = $this->recognise($source, $row);
            $result instanceof PreviewBill ? $bills[] = $result : $issues[] = $result;
        }

        return new ImportPreview($source, count($rows), $bills, $duplicates, $issues, $fees);
    }

    private function recognise(string $source, ImportRow $row): PreviewBill|PreviewIssue
    {
        $provider = Provider::fromFile($source, $row->providerName);
        $issue = fn (string $reason) => new PreviewIssue($row, $reason, $provider?->id);

        if ($row->reference === null) {
            return $issue('no_reference');
        }
        if ($row->amountCents === null || $row->amountCents <= 0) {
            return $issue('bad_amount');
        }
        if ($row->paidAt === null) {
            return $issue('bad_date');
        }
        if ($provider === null) {
            return $issue('unknown_provider');
        }

        $account = $row->number === null ? null : UtilityAccount::query()
            ->withoutGlobalScope(RegistryVisibilityScope::class)
            ->with('properties')
            ->where('provider_id', $provider->id)
            ->where('number_key', UtilityAccount::numberKey($row->number))
            ->first();

        if ($account === null) {
            return $issue('unknown_number');
        }
        if ($account->isFixed()) {
            return $issue('fixed_account');
        }
        $day = $row->paidAt->startOfDay();
        if ($account->isArchived() || $day->lt($account->valid_from) || ($account->valid_to !== null && $day->gt($account->valid_to))) {
            return $issue('inactive_account');
        }

        [$parts, $shares] = self::split($row->amountCents, $account);

        return new PreviewBill($row, $account, $parts, $shares);
    }

    /**
     * Дели сумата по съотношението: всеки имот без последния получава
     * сума × дял (закръглено до цент, половината нагоре); последният — остатъка.
     *
     * @return array{0: array<int, string>, 1: array<int, string>}
     */
    public static function split(int $cents, UtilityAccount $account): array
    {
        $lines = $account->properties->sortBy('position')->values();
        $parts = $shares = [];
        $given = 0;

        foreach ($lines as $index => $line) {
            $basisPoints = (int) round(((float) $line->share) * 100);
            $amount = $index === $lines->count() - 1
                ? $cents - $given
                : intdiv($cents * $basisPoints + 5000, 10000);
            $given += $amount;
            $parts[(int) $line->property_id] = Cents::format($amount);
            $shares[(int) $line->property_id] = $line->share;
        }

        return [$parts, $shares];
    }

    /** Записва платената сметка, частите ѝ и записите по 407. */
    private function book(int $batchId, string $source, PreviewBill $preview): PaidUtilityBill
    {
        $row = $preview->row;

        $bill = PaidUtilityBill::query()->create([
            'import_batch_id' => $batchId, 'utility_account_id' => $preview->account->id, 'source' => $source,
            'reference' => $row->reference, 'paid_at' => $row->paidAt, 'amount' => Cents::format($row->amountCents),
            'provider_name' => $row->providerName, 'file_number' => $row->number,
            'description' => $row->description === null ? null : mb_substr($row->description, 0, 500), 'details' => $row->details,
        ]);

        foreach ($preview->parts as $propertyId => $amount) {
            PaidUtilityBillPart::query()->create([
                'paid_utility_bill_id' => $bill->id, 'property_id' => $propertyId,
                'share' => $preview->shares[$propertyId], 'amount' => $amount,
            ]);

            Ledger::book($row->paidAt->startOfMonth()->startOfDay(), LedgerKind::UtilityPaid, [
                'account' => LedgerAccount::Utility, 'type' => ChargeType::Utility,
                'property_id' => $propertyId, 'paid_utility_bill_id' => $bill->id,
            ], Cents::from($amount));
        }

        return $bill;
    }

    private function billExists(string $source, string $reference): bool
    {
        return PaidUtilityBill::query()->withoutGlobalScope(RegistryVisibilityScope::class)
            ->where('source', $source)->where('reference', $reference)->exists();
    }

    private function ensureCanManage(): void
    {
        if (Auth::hasUser() && ! WorksheetRules::canManage(Auth::user())) {
            throw new AuthorizationException(__('charges.messages.cannot_manage'));
        }
    }

    private function companyId(): int
    {
        return CompanyScope::restrictedCompanyId() ?? throw new LogicException('Вносът иска влязъл служител на фирма.');
    }
}
