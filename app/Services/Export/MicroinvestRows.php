<?php

namespace App\Services\Export;

use App\Enums\ChargeType;
use App\Enums\LedgerAccount;
use App\Enums\LedgerKind;
use App\Enums\OwnerContractType;
use App\Models\ExportBatch;
use App\Services\Charges\Cents;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Редовете „дебит-кредит“ за Микроинвест от записите в един пакет (PROJECT.md,
 * т. 4 и „Експорт към Микроинвест“, 26.11.2026). В книгата всеки запис е по
 * една сметка; тук двойките се сглобяват:
 *
 * - наем: 418/договор/имот → 408/договор за управление/имот (при „Собствен“ →
 *   сметката от настройките); приход: 408/… → приход по тип договор = наемът
 *   минус дължимото към собственика (свободен имот при фиксиран — с минус);
 * - битова сметка към наемател: 418/… → 407/вид/имот (общ абонат: 407/абонатен номер);
 * - фиксирана такса: 418/… → фонд/номера на фонда;
 * - депозит 418 → 491/договор/имот; неустойка и „Друго“ → сметките от настройките;
 * - платена сметка (по настройка): 407/… → банка (ePay/EasyPay); платена от фонда: фонд → банка;
 * - за сметка на управителя: разход → 407/….
 *
 * Сверката: по всяка синтетична сметка на разчетите (418, 491, 408, 407, фонд)
 * „дебит минус кредит“ във файла трябва да е равно на сбора в книгата.
 * Само чете. Номерата на сметките са от настройките.
 */
final class MicroinvestRows
{
    public function __construct(private readonly ExportSettings $settings) {}

    /**
     * @return array{month: CarbonImmutable, rows: list<array{cents: int, debit: string, credit: string, basis: string, document: string}>,
     *     check: list<array{account: string, ledger: int, file: int}>, ok: bool}
     */
    public function build(ExportBatch $batch): array
    {
        $s = $this->settings;
        $companyId = (int) $batch->company_id;
        $month = CarbonImmutable::parse($batch->month)->startOfMonth();
        $entries = DB::table('ledger_entries')->where('company_id', $companyId)
            ->whereIn('id', DB::table('export_batch_entries')->where('company_id', $companyId)->where('export_batch_id', $batch->id)->select('ledger_entry_id'))
            ->orderBy('id')->get();

        $lookup = fn (string $table, Collection $ids, string $column = 'number') => DB::table($table)->where('company_id', $companyId)
            ->whereIn('id', $ids->filter()->unique()->values())->pluck($column, 'id');
        $leases = $lookup('lease_contracts', $entries->pluck('lease_contract_id'));
        $properties = DB::table('properties')->where('company_id', $companyId)->pluck('number', 'id');
        $statements = $lookup('statements', $entries->pluck('statement_id'));
        $funds = DB::table('funds')->where('company_id', $companyId)->pluck('number', 'id');
        $bills = DB::table('paid_utility_bills')->where('company_id', $companyId)->whereIn('id', $entries->pluck('paid_utility_bill_id')->filter()->unique())
            ->get(['id', 'utility_account_id', 'source', 'reference'])->keyBy('id');
        $accounts = DB::table('utility_accounts')->where('company_id', $companyId)->get(['id', 'number', 'kind', 'fund_id'])->keyBy('id');
        $shares = DB::table('utility_account_properties')->where('company_id', $companyId)->selectRaw('utility_account_id, COUNT(*) as n')
            ->groupBy('utility_account_id')->pluck('n', 'utility_account_id');
        $contracts = DB::table('owner_contracts')->where('company_id', $companyId)->get(['id', 'number', 'type'])->keyBy('id');

        $lease = fn ($e) => $leases[$e->lease_contract_id] ?? '?';
        $property = fn ($e) => $properties[$e->property_id] ?? '?';
        $kindLabel = fn (?string $kind) => $kind ? __('utilities.kinds.'.$kind) : __('export.file.basis.utility');
        // 407 на сметката за имота: абонат с един имот → 407/вид/имот, общ → 407/абонатен номер.
        $utility = function (?int $accountId, $propertyId) use ($s, $accounts, $shares, $properties) {
            $account = $accounts[$accountId] ?? null;
            if ($account === null) {
                return $s->get('utility').'/?';
            }

            return (int) ($shares[$account->id] ?? 0) > 1
                ? $s->get('utility').'/'.$account->number
                : $s->get('utility').'/'.($s->kindCode($account->kind) ?? '?').'/'.($properties[$propertyId] ?? '?');
        };
        // Фонд: сметката от настройките/номерът на фонда (уточнено 26.11.2026).
        $fund = fn ($fundId) => $s->get('fund').'/'.($funds[$fundId] ?? '?');
        $document = fn ($e) => $e->statement_id ? (string) ($statements[$e->statement_id] ?? '') : (string) $e->id;

        // Договорът за управление на имот за месеца: по записите по 408 за месеца, иначе действащият по редовете.
        $ownerOf = function (int $propertyId) use ($companyId, $month, $contracts) {
            $ids = DB::table('ledger_entries')->where('company_id', $companyId)->where('account', LedgerAccount::Owner->value)
                ->whereDate('month', $month->toDateString())->where('property_id', $propertyId)->distinct()->pluck('owner_contract_id')->filter();
            if ($ids->count() === 1) {
                return $contracts[$ids->first()] ?? null;
            }
            $id = DB::table('owner_contract_lines')->where('company_id', $companyId)->where('property_id', $propertyId)
                ->whereDate('valid_from', '<=', $month->endOfMonth()->toDateString())
                ->where(fn ($q) => $q->whereNull('valid_to')->orWhereDate('valid_to', '>', $month->toDateString()))
                ->orderByDesc('valid_from')->value('owner_contract_id');

            return $contracts[$id] ?? null;
        };

        $rows = [];
        $row = fn (int $cents, string $debit, string $credit, string $basis, string $document) => ['cents' => $cents, 'debit' => $debit, 'credit' => $credit, 'basis' => $basis, 'document' => $document];
        $toOwner = [];     // [договор][имот] => наем към 408 в този пакет
        $documents = [];   // [договор][имот] => известията на наема
        $included = [];
        $paid = [];       // платените сметки: един ред за плащане

        foreach ($entries as $e) {
            $cents = Cents::from((string) $e->amount);
            $account = LedgerAccount::from($e->account);
            $kind = LedgerKind::from($e->kind);
            $type = ChargeType::from($e->type);

            if ($account === LedgerAccount::Tenant) {
                $included[] = $e;
                $debit = $s->get('tenant').'/'.$lease($e).'/'.$property($e);
                if ($type === ChargeType::Rent) {
                    $contract = $ownerOf((int) $e->property_id);
                    if ($contract !== null && $contract->type === OwnerContractType::Own->value) {
                        $rows[] = $row($cents, $debit, (string) $s->get('revenue_own'), __('export.file.basis.rent'), $document($e));
                    } else {
                        $number = $contract?->number ?? '?';
                        $rows[] = $row($cents, $debit, $s->get('owner').'/'.$number.'/'.$property($e), __('export.file.basis.rent'), $document($e));
                        $toOwner[$contract?->id ?? 0][$e->property_id] = ($toOwner[$contract?->id ?? 0][$e->property_id] ?? 0) + $cents;
                        $documents[$contract?->id ?? 0][$e->property_id][] = $document($e);
                    }
                } elseif ($type === ChargeType::Utility) {
                    $bill = $bills[$e->paid_utility_bill_id] ?? null;
                    $rows[] = $row($cents, $debit, $utility($bill?->utility_account_id, $e->property_id), $kindLabel($accounts[$bill?->utility_account_id]->kind ?? null), $document($e));
                } elseif ($type === ChargeType::FixedFee) {
                    $line = DB::table('statement_lines')->where('company_id', $companyId)->where('statement_id', $e->statement_id)
                        ->where('property_id', $e->property_id)->where('type', ChargeType::FixedFee->value)
                        ->orderByRaw('amount = ? desc', [$e->amount])->first();
                    $feeAccount = $accounts[$line?->utility_account_id] ?? null;
                    $rows[] = $row($cents, $debit, $fund($feeAccount?->fund_id), $kindLabel($feeAccount?->kind), $document($e));
                } elseif ($type === ChargeType::Deposit) {
                    $rows[] = $row($cents, $debit, $s->get('deposit').'/'.$lease($e).'/'.$property($e), __('export.file.basis.deposit'), $document($e));
                } elseif ($type === ChargeType::Penalty) {
                    $rows[] = $row($cents, $debit, (string) $s->get('revenue_penalty'), __('export.file.basis.penalty'), $document($e));
                } else {
                    $rows[] = $row($cents, $debit, (string) $s->get('revenue_other'), __('export.file.basis.other'), $document($e));
                }
            } elseif ($account === LedgerAccount::Owner) {
                $included[] = $e;
                $toOwner[$e->owner_contract_id][$e->property_id] = ($toOwner[$e->owner_contract_id][$e->property_id] ?? 0) - $cents;
            } elseif ($account === LedgerAccount::Deposit) {
                $included[] = $e; // двойката е редът „депозит“ от 418
            } elseif ($account === LedgerAccount::Utility) {
                $bill = $bills[$e->paid_utility_bill_id] ?? null;
                $billAccount = $accounts[$bill?->utility_account_id] ?? null;
                $bank = $bill?->source === 'epay' ? $s->get('bank_epay') : $s->get('bank_easypay');
                if ($kind === LedgerKind::UtilityPaid || $kind === LedgerKind::FundExpense) {
                    if (! $s->includePaidBills()) {
                        continue; // не се експортира и не влиза в сверката
                    }
                    $included[] = $e;
                    $debit = $kind === LedgerKind::FundExpense ? $fund($e->fund_id) : $utility($bill?->utility_account_id, $e->property_id);
                    // Едно плащане е един ред: частите по имотите на общия абонат се събират.
                    $key = $debit.'|'.$bank.'|'.($bill?->reference ?? $e->id);
                    $paid[$key] ??= $row(0, $debit, (string) $bank, $kindLabel($billAccount?->kind), (string) ($bill?->reference ?? $e->id));
                    $paid[$key]['cents'] += $cents;
                } elseif ($kind === LedgerKind::ManagerCost) {
                    $included[] = $e;
                    $rows[] = $row(-$cents, (string) $s->get('manager_cost'), $utility($bill?->utility_account_id, $e->property_id),
                        $kindLabel($billAccount?->kind), (string) ($bill?->reference ?? $e->id));
                } else {
                    $included[] = $e; // двойката е редът от 418 (битова сметка или фиксирана такса към фонда)
                }
            }
        }

        array_push($rows, ...array_values($paid));

        // Приход по договор за управление и имот: наемът към 408 минус дължимото към собственика.
        foreach ($toOwner as $contractId => $byProperty) {
            $contract = $contracts[$contractId] ?? null;
            foreach ($byProperty as $propertyId => $cents) {
                if ($cents === 0) {
                    continue;
                }
                $docs = array_values(array_unique($documents[$contractId][$propertyId] ?? []));
                $rows[] = $row($cents, $s->get('owner').'/'.($contract?->number ?? '?').'/'.($properties[$propertyId] ?? '?'),
                    (string) $s->get('revenue_'.($contract?->type ?? 'representative_percent')), __('export.file.basis.rent'), count($docs) === 1 ? $docs[0] : '');
            }
        }

        return ['month' => $month, 'rows' => $rows] + $this->check($rows, $included);
    }

    /** Сверка по синтетичните сметки на разчетите: „дебит минус кредит“ във файла = сборът в книгата. */
    private function check(array $rows, array $included): array
    {
        $s = $this->settings;
        $sign = [LedgerAccount::Tenant->value => 1, LedgerAccount::Deposit->value => -1, LedgerAccount::Owner->value => -1, LedgerAccount::Utility->value => 1];
        $ledger = [];
        foreach ($included as $e) {
            $synthetic = match (true) {
                $e->account === LedgerAccount::Utility->value && $e->fund_id !== null => $s->get('fund'),
                $e->account === LedgerAccount::Utility->value => $s->get('utility'),
                $e->account === LedgerAccount::Tenant->value => $s->get('tenant'),
                $e->account === LedgerAccount::Deposit->value => $s->get('deposit'),
                default => $s->get('owner'),
            };
            $ledger[$synthetic] = ($ledger[$synthetic] ?? 0) + $sign[$e->account] * Cents::from((string) $e->amount);
        }

        $file = [];
        foreach ($rows as $row) {
            $file[substr($row['debit'], 0, 3)] = ($file[substr($row['debit'], 0, 3)] ?? 0) + $row['cents'];
            $file[substr($row['credit'], 0, 3)] = ($file[substr($row['credit'], 0, 3)] ?? 0) - $row['cents'];
        }

        $check = [];
        foreach (array_unique(array_filter([$s->get('tenant'), $s->get('deposit'), $s->get('owner'), $s->get('utility'), $s->get('fund')])) as $synthetic) {
            $check[] = ['account' => $synthetic, 'ledger' => $ledger[$synthetic] ?? 0, 'file' => $file[$synthetic] ?? 0];
        }
        $unknown = array_filter($rows, fn ($row) => str_contains($row['debit'].$row['credit'], '?'));

        return ['check' => $check, 'ok' => $unknown === [] && array_filter($check, fn ($c) => $c['ledger'] !== $c['file']) === []];
    }
}
