<?php

namespace App\Services\Payments;

use App\Enums\LedgerAccount;
use App\Enums\LedgerKind;
use App\Services\Charges\Cents;
use Illuminate\Support\Facades\DB;

/**
 * Разнасянето на изплащанията по един договор за управление (PROJECT.md,
 * „Плащания и салда“, 26.11.2026). Смята се от книгата: начисленото по 408
 * (без изплащанията), по месец и имот; изплащанията (и корекциите с минус)
 * погасяват най-старите месеци първи, а в месец — по номер на имота.
 */
final class OwnerAllocation
{
    /**
     * @return array{debts: list<array{month: string, property_id: int, amount: int, paid: int}>, available: int, accrued: int, paid_out: int}
     */
    public function forContract(int $companyId, int $contractId): array
    {
        $entries = DB::table('ledger_entries')->where('company_id', $companyId)->where('owner_contract_id', $contractId)
            ->where('account', LedgerAccount::Owner->value)->get(['month', 'property_id', 'amount', 'kind']);
        $numbers = DB::table('properties')->where('company_id', $companyId)->whereIn('id', $entries->pluck('property_id')->unique())->pluck('number', 'id');

        $groups = [];
        $available = 0;
        $paidOut = 0;
        foreach ($entries as $e) {
            $cents = Cents::from((string) $e->amount);
            if (in_array($e->kind, [LedgerKind::Payout->value, LedgerKind::PayoutReversal->value], true)) {
                $available -= $cents;
                $paidOut -= $cents;
                continue;
            }
            $key = $e->month.'|'.$e->property_id;
            $groups[$key] ??= ['month' => (string) $e->month, 'property_id' => (int) $e->property_id, 'amount' => 0, 'paid' => 0];
            $groups[$key]['amount'] += $cents;
        }

        $debts = [];
        $accrued = 0;
        foreach ($groups as $group) {
            $accrued += $group['amount'];
            if ($group['amount'] < 0) {
                $available -= $group['amount']; // например свободен имот с корекция
            } elseif ($group['amount'] > 0) {
                $debts[] = $group;
            }
        }
        usort($debts, fn ($a, $b) => [$a['month'], $numbers[$a['property_id']] ?? 0] <=> [$b['month'], $numbers[$b['property_id']] ?? 0]);
        foreach ($debts as $i => $debt) {
            $paid = max(0, min($debt['amount'], $available));
            $debts[$i]['paid'] = $paid;
            $available -= $paid;
        }

        return ['debts' => $debts, 'available' => $available, 'accrued' => $accrued, 'paid_out' => $paidOut];
    }

    /** Изплатеното за месеца по договора, в центове. */
    public function paidForMonth(int $companyId, int $contractId, string $month): int
    {
        return array_sum(array_map(fn ($d) => str_starts_with($d['month'], $month) ? $d['paid'] : 0, $this->forContract($companyId, $contractId)['debts']));
    }

    /**
     * Разделяне на ново изплащане по имотите: най-старите месеци първи;
     * остатъкът (надплатено) — към първия имот на договора.
     *
     * @return array<int, int> имот => центове
     */
    public function split(int $companyId, int $contractId, int $cents): array
    {
        $split = [];
        $left = $cents;
        foreach ($this->forContract($companyId, $contractId)['debts'] as $debt) {
            $open = $debt['amount'] - $debt['paid'];
            if ($open <= 0 || $left <= 0) {
                continue;
            }
            $take = min($open, $left);
            $split[$debt['property_id']] = ($split[$debt['property_id']] ?? 0) + $take;
            $left -= $take;
        }
        if ($left > 0) {
            $first = (int) DB::table('owner_contract_lines')->where('company_id', $companyId)->where('owner_contract_id', $contractId)
                ->orderBy('valid_from')->orderBy('id')->value('property_id');
            $split[$first] = ($split[$first] ?? 0) + $left;
        }

        return $split;
    }
}
