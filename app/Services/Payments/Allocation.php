<?php

namespace App\Services\Payments;

use App\Enums\ChargeType;
use App\Enums\LedgerAccount;
use App\Enums\LedgerKind;
use App\Services\Charges\Cents;
use Illuminate\Support\Facades\DB;

/**
 * Разнасянето на плащанията по един договор за наем (PROJECT.md, „Плащания и
 * салда“, 26.11.2026). Смята се от книгата, не се записва:
 *
 * - дълговете са начисленията по 418 (без плащанията), събрани по месец,
 *   известие, вид и имот; редът е: първо най-старият месец, после известието, а
 *   в едно известие наем → битови сметки и такси → неустойка и „Друго“ →
 *   депозит; при равни — по номер на имота;
 * - плащанията (и корекциите с минус) образуват „наличното“, което покрива
 *   дълговете по този ред; останалото е надплатено.
 */
final class Allocation
{
    /** Редът на видовете в едно известие. */
    public static function rank(string $type): int
    {
        return match ($type) {
            ChargeType::Rent->value => 0,
            ChargeType::Utility->value, ChargeType::FixedFee->value => 1,
            ChargeType::Penalty->value, ChargeType::Other->value => 2,
            ChargeType::Deposit->value => 3,
            default => 4,
        };
    }

    /**
     * @return array{debts: list<array{month: string, statement_id: ?int, type: string, property_id: int, amount: int, paid: int}>, available: int}
     *     available — неизползваното от плащанията (надплатено), в центове
     */
    public function forLease(int $companyId, int $leaseId): array
    {
        $entries = DB::table('ledger_entries')->where('company_id', $companyId)->where('lease_contract_id', $leaseId)
            ->where('account', LedgerAccount::Tenant->value)->get(['month', 'statement_id', 'type', 'property_id', 'amount', 'kind']);
        $numbers = DB::table('properties')->where('company_id', $companyId)->whereIn('id', $entries->pluck('property_id')->unique())->pluck('number', 'id');
        $statements = DB::table('statements')->where('company_id', $companyId)->whereIn('id', $entries->pluck('statement_id')->filter()->unique())->pluck('number', 'id');

        $groups = [];
        $available = 0;
        foreach ($entries as $e) {
            $cents = Cents::from((string) $e->amount);
            if (in_array($e->kind, [LedgerKind::Payment->value, LedgerKind::PaymentReversal->value], true)) {
                $available -= $cents;
                continue;
            }
            $key = $e->month.'|'.($e->statement_id ?? 0).'|'.$e->type.'|'.$e->property_id;
            $groups[$key] ??= ['month' => (string) $e->month, 'statement_id' => $e->statement_id ? (int) $e->statement_id : null,
                'type' => $e->type, 'property_id' => (int) $e->property_id, 'amount' => 0, 'paid' => 0];
            $groups[$key]['amount'] += $cents;
        }

        $debts = [];
        foreach ($groups as $group) {
            if ($group['amount'] < 0) {
                $available -= $group['amount']; // корекция в полза на наемателя
            } elseif ($group['amount'] > 0) {
                $debts[] = $group;
            }
        }
        usort($debts, fn ($a, $b) => [$a['month'], $statements[$a['statement_id']] ?? PHP_INT_MAX, self::rank($a['type']), $numbers[$a['property_id']] ?? 0]
            <=> [$b['month'], $statements[$b['statement_id']] ?? PHP_INT_MAX, self::rank($b['type']), $numbers[$b['property_id']] ?? 0]);

        foreach ($debts as $i => $debt) {
            $paid = max(0, min($debt['amount'], $available));
            $debts[$i]['paid'] = $paid;
            $available -= $paid;
        }

        return ['debts' => $debts, 'available' => $available];
    }

    /**
     * Как се разделя ново плащане по имотите: по неплатените дългове в реда на
     * разнасянето; остатъкът (надплатено) — към първия имот на договора.
     *
     * @return array<int, int> имот => центове
     */
    public function split(int $companyId, int $leaseId, int $cents): array
    {
        $state = $this->forLease($companyId, $leaseId);
        $split = [];
        $left = $cents;
        foreach ($state['debts'] as $debt) {
            $open = $debt['amount'] - $debt['paid'];
            if ($open <= 0 || $left <= 0) {
                continue;
            }
            $take = min($open, $left);
            $split[$debt['property_id']] = ($split[$debt['property_id']] ?? 0) + $take;
            $left -= $take;
        }
        if ($left > 0) {
            $first = (int) DB::table('lease_contract_lines')->where('company_id', $companyId)->where('lease_contract_id', $leaseId)
                ->orderBy('valid_from')->orderBy('id')->value('property_id');
            $split[$first] = ($split[$first] ?? 0) + $left;
        }

        return $split;
    }
}
