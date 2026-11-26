<?php

namespace App\Services\Opening;

use App\Enums\ChargeType;
use App\Enums\LedgerAccount;
use App\Enums\LedgerKind;
use App\Models\Fund;
use App\Models\LeaseContract;
use App\Models\LedgerEntry;
use App\Models\OpeningImport;
use App\Models\PeriodLock;
use App\Models\Property;
use App\Models\Scopes\CompanyScope;
use App\Models\Scopes\RegistryVisibilityScope;
use App\Services\Charges\Cents;
use App\Services\Charges\Ledger;
use App\Support\BgDate;
use App\Support\Xlsx\XlsxReader;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Началните салда (PROJECT.md, „Начални салда“, 26.11.2026):
 *
 * - два файла .xlsx (оборотна ведомост): заглавният ред „Номер / Дебит / Кредит“ се
 *   търси сам; вземат се само редовете сметка/договор/имот, общите се пропускат;
 * - 418: дебит − кредит (дължи или надплатено); 491: кредитът (депозит, начислен и
 *   внесен: 491 +, 418 + и 418 −); фондовете: парите във фонда (407 на фонда с минус);
 * - преглед преди запис; всичко или нищо; един внос за фирма; датата е преди първия
 *   издаден месец; сторно на целия внос с причина (кодът се проверява в контролера).
 */
final class OpeningBalances
{
    private function companyId(): int
    {
        return CompanyScope::restrictedCompanyId() ?? throw new \LogicException('Началните салда искат влязъл служител на фирма.');
    }

    /**
     * Прочита и проверява. Грешки в полетата (дата, файл без заглавие, фонд) — ValidationException;
     * грешките по редовете са в самите редове.
     *
     * @return array{as_of: string, rows: list<array>, funds: array<int, int>, totals: array, has_errors: bool}
     */
    public function preview(?string $asOf, ?UploadedFile $tenants, ?UploadedFile $deposits, array $funds): array
    {
        $errors = [];
        $date = BgDate::parse($asOf);
        if ($date === null) {
            $errors['as_of'] = __('opening.errors.date');
        } elseif (OpeningImport::active() !== null) {
            $errors['as_of'] = __('opening.errors.already');
        } elseif (($first = PeriodLock::query()->orderBy('month')->value('month')) !== null && ! $date->lt(CarbonImmutable::parse($first)->startOfMonth())) {
            $errors['as_of'] = __('opening.errors.after_issued', ['month' => \App\Services\Charges\Month::label(CarbonImmutable::parse($first))]);
        }

        $rows = [];
        foreach (['tenants' => [$tenants, LedgerAccount::Tenant->value], 'deposits' => [$deposits, LedgerAccount::Deposit->value]] as $field => [$file, $account]) {
            if ($file === null) {
                continue;
            }
            $read = $this->read($file);
            if ($read === null) {
                $errors[$field] = __('opening.errors.header');
                continue;
            }
            array_push($rows, ...$this->rows($read, $account));
        }
        if ($tenants === null && $deposits === null && array_filter($funds, fn ($v) => trim((string) $v) !== '') === []) {
            $errors['tenants'] = __('opening.errors.nothing');
        }

        $fundCents = [];
        foreach ($funds as $id => $value) {
            $value = trim((string) $value);
            if ($value === '') {
                continue;
            }
            $cents = $this->amount($value, true);
            if ($cents === null || Fund::query()->find($id) === null) {
                $errors["funds.$id"] = __('opening.errors.amount');
                continue;
            }
            if ($cents !== 0) {
                $fundCents[(int) $id] = $cents;
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $sum = fn (string $account, string $key) => array_sum(array_map(fn ($r) => $r['account'] === $account ? $r[$key] : 0, $rows));

        return [
            'as_of' => $date->toDateString(),
            'rows' => $rows,
            'funds' => $fundCents,
            'totals' => ['debit' => $sum('418', 'debit'), 'credit' => $sum('418', 'credit'), 'deposits' => $sum('491', 'credit'), 'funds' => array_sum($fundCents)],
            'has_errors' => array_filter($rows, fn ($r) => $r['error'] !== null) !== [],
        ];
    }

    /** @return ?list<array{number: string, debit: string, credit: string}> редовете под заглавието или null без заглавие */
    private function read(UploadedFile $file): ?array
    {
        $sheet = (new XlsxReader)->rows($file->getRealPath());
        if ($sheet === null) {
            return null;
        }
        foreach ($sheet as $i => $cells) {
            $names = array_map(fn ($c) => mb_strtolower(trim((string) $c)), $cells);
            $number = array_search('номер', $names, true);
            $debit = array_search('дебит', $names, true);
            $credit = array_search('кредит', $names, true);
            if ($number !== false && $debit !== false && $credit !== false) {
                return array_map(fn ($row) => ['number' => trim((string) ($row[$number] ?? '')), 'debit' => (string) ($row[$debit] ?? ''), 'credit' => (string) ($row[$credit] ?? '')],
                    array_slice($sheet, $i + 1));
            }
        }

        return null;
    }

    /** Редовете сметка/договор/имот с проверката им; общите редове се пропускат. */
    private function rows(array $lines, string $account): array
    {
        $rows = [];
        $seen = [];
        foreach ($lines as $line) {
            $number = $line['number'];
            if (! preg_match('#^\d#', $number)) {
                continue; // празен ред или текст („Общо“)
            }
            $parts = explode('/', $number);
            if (count($parts) < 3 && ctype_digit(str_replace('/', '', $number))) {
                if ($parts[0] === $account) {
                    continue; // общ ред (418 или 418/договор)
                }
            }
            $row = ['number' => $number, 'account' => $parts[0], 'lease_id' => null, 'lease' => null, 'tenant' => null, 'tenant_id' => null,
                'property_id' => null, 'property' => null, 'debit' => 0, 'credit' => 0, 'error' => null];
            $debit = $this->amount($line['debit'], false);
            $credit = $this->amount($line['credit'], false);

            if (count($parts) !== 3 || ! ctype_digit(implode('', $parts))) {
                $row['error'] = __('opening.errors.format');
            } elseif ($parts[0] !== $account) {
                $row['error'] = __('opening.errors.wrong_account');
            } elseif ($debit === null || $credit === null) {
                $row['error'] = __('opening.errors.amount');
            } else {
                $row['debit'] = $account === LedgerAccount::Deposit->value ? 0 : $debit; // при 491 дебитът се пренебрегва
                $row['credit'] = $credit;
                $lease = LeaseContract::query()->with('tenant')->where('number', (int) $parts[1])->first();
                $property = $lease ? Property::query()->where('number', (int) $parts[2])->first() : null;
                if ($lease === null) {
                    $row['error'] = __('opening.errors.contract_not_found');
                } elseif ($property === null || ! $lease->lines()->where('property_id', $property->id)->exists()) {
                    $row['error'] = __('opening.errors.property_not_in_contract');
                } elseif (isset($seen[$number])) {
                    $row['error'] = __('opening.errors.duplicate');
                } else {
                    $row = array_merge($row, ['lease_id' => $lease->id, 'lease' => $lease->number, 'tenant_id' => $lease->tenant_id,
                        'tenant' => $lease->tenant?->name, 'property_id' => $property->id, 'property' => $property->number]);
                }
            }
            $seen[$number] = true;
            $rows[] = $row;
        }

        return $rows;
    }

    /** Сума в центове („1 234,56“, „1234.56“, число от Excel); празно = 0; null при грешка. */
    private function amount(string $value, bool $signed): ?int
    {
        $text = str_replace([' ', "\u{00A0}"], '', trim($value));
        if ($text === '') {
            return 0;
        }
        $text = str_contains($text, ',') && ! str_contains($text, '.') ? str_replace(',', '.', $text) : str_replace(',', '', $text);
        if (! preg_match($signed ? '/^-?\d+(\.\d+)?$/' : '/^\d+(\.\d+)?$/', $text)) {
            return null;
        }

        return (int) round(((float) $text) * 100);
    }

    /** Запис на прегледа: всичко или нищо. */
    public function confirm(?array $preview): OpeningImport
    {
        if ($preview === null || $preview['has_errors']) {
            throw ValidationException::withMessages(['file' => __('opening.errors.no_preview')]);
        }

        return DB::transaction(function () use ($preview) {
            Ledger::lockCompany($this->companyId());
            if (OpeningImport::active() !== null) {
                throw ValidationException::withMessages(['file' => __('opening.errors.already')]);
            }
            $asOf = CarbonImmutable::parse($preview['as_of']);
            $month = $asOf->startOfMonth();

            $import = new OpeningImport;
            $import->forceFill(['as_of' => $asOf, 'rows_count' => count($preview['rows']) + count($preview['funds']),
                'created_by' => Auth::id(), 'created_at' => now()])->save();
            $reason = __('opening.reason', ['date' => $asOf->format('d.m.Y')]);

            foreach ($preview['rows'] as $row) {
                $tenant = ['lease_contract_id' => $row['lease_id'], 'tenant_id' => $row['tenant_id'], 'property_id' => $row['property_id'],
                    'opening_import_id' => $import->id, 'reason' => $reason];
                if ($row['account'] === LedgerAccount::Tenant->value) {
                    Ledger::book($month, LedgerKind::Opening, $tenant + ['account' => LedgerAccount::Tenant, 'type' => ChargeType::Opening], $row['debit'] - $row['credit']);
                } elseif ($row['credit'] > 0) {
                    // Депозит: държан (491) и броен за начислен и внесен (418 + и 418 −).
                    Ledger::book($month, LedgerKind::Opening, $tenant + ['account' => LedgerAccount::Deposit, 'type' => ChargeType::Deposit], $row['credit']);
                    Ledger::book($month, LedgerKind::Opening, $tenant + ['account' => LedgerAccount::Tenant, 'type' => ChargeType::Deposit], $row['credit']);
                    Ledger::book($month, LedgerKind::Opening, $tenant + ['account' => LedgerAccount::Tenant, 'type' => ChargeType::Deposit], -$row['credit']);
                }
            }
            foreach ($preview['funds'] as $fundId => $cents) {
                Ledger::book($month, LedgerKind::Opening, ['account' => LedgerAccount::Utility, 'type' => ChargeType::Opening, 'fund_id' => $fundId,
                    'opening_import_id' => $import->id, 'reason' => $reason], -$cents);
            }

            return $import;
        });
    }

    /** Сторно на целия внос: обратни записи за всичко, с причина. */
    public function reverse(?string $reason): OpeningImport
    {
        $reason = trim((string) $reason);
        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => __('opening.errors.reason')]);
        }

        return DB::transaction(function () use ($reason) {
            Ledger::lockCompany($this->companyId());
            $import = OpeningImport::active() ?? throw ValidationException::withMessages(['reason' => __('opening.errors.nothing_to_reverse')]);

            $reversal = new OpeningImport;
            $reversal->forceFill(['as_of' => $import->as_of, 'rows_count' => $import->rows_count, 'reversal_of_id' => $import->id,
                'reason' => mb_substr($reason, 0, 500), 'created_by' => Auth::id(), 'created_at' => now()])->save();

            $entries = LedgerEntry::query()->withoutGlobalScope(RegistryVisibilityScope::class)->where('opening_import_id', $import->id)->orderBy('id')->get();
            foreach ($entries as $entry) {
                Ledger::book($entry->month, LedgerKind::OpeningReversal, [
                    'account' => $entry->account, 'type' => $entry->type, 'lease_contract_id' => $entry->lease_contract_id,
                    'tenant_id' => $entry->tenant_id, 'property_id' => $entry->property_id, 'fund_id' => $entry->fund_id,
                    'opening_import_id' => $reversal->id, 'reason' => $reversal->reason,
                ], -Cents::from((string) $entry->amount));
            }

            return $reversal;
        });
    }
}
