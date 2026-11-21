<?php

namespace App\Services\Charges;

use App\Enums\CalculationMethod;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

/**
 * Сметката на наема и на дължимото към собственика за един месец (PROJECT.md,
 * т. 3.1, 3.6 и т. 2 „Начисления, правила“, „Собственикът“, „Начисления,
 * уточнения“). Само смята: не записва нищо.
 *
 * Наемът е предварителен, за месец М, по дни: първият ден се брои, последният
 * и датата на прекратяване не. Сумата е наем × дни ÷ дните в месеца. При
 * промяна в средата на месеца (нов наем или процент с анекс) частите се
 * сборуват и се закръглят веднъж за имота и месеца.
 *
 * Сметката е по дни: за всеки ден от месеца се гледа кой договор за наем и
 * кой договор за управление важат за имота. Архивираните договори не се броят.
 *
 * Всичко е в цели центове; процентът е в стотни от процента (10,00% = 1000).
 * Без препълване до наем от 1 000 000 € на месец (по-голяма сума се отказва).
 */
class ChargeCalculator
{
    private const MAX_CENTS = 100_000_000;

    /**
     * @param  array<string, string>  $rentOverrides  „договор за наем:имот“ → наемът, който ще се начисли
     *     (ръчно променен в работната таблица; 0 = махнат). Дялът на собственика при процент се смята от него
     *     (PROJECT.md, „Дял на собственика“): отстъпката е промяна на реда за наем.
     * @param  list<int>|null  $onlyLeases  броят се само тези договори за наем (null = всички); за
     *     „Издай сега“ и сверката на издаден месец
     */
    public function forMonth(int $companyId, string $month, array $rentOverrides = [], ?array $onlyLeases = null): MonthlyCharges
    {
        if (! preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $month, $m)) {
            throw new InvalidArgumentException("Месецът трябва да е във вида гггг-мм: {$month}");
        }

        $start = CarbonImmutable::create((int) $m[1], (int) $m[2], 1)->startOfDay();
        $end = $start->addMonth();
        $daysInMonth = $start->daysInMonth;

        $leaseDays = $this->leaseDays($companyId, $start, $end, $onlyLeases);
        $managementDays = $this->managementDays($companyId, $start, $end);
        $leaseContracts = [];
        foreach ($leaseDays as $days) {
            foreach ($days as $day) {
                $leaseContracts[$day['contract']] = true;
            }
        }
        $firstLines = $this->firstLines($companyId, array_keys($leaseContracts));

        $charges = [];
        $forDecision = [];

        $properties = array_unique(array_merge(array_keys($leaseDays), array_keys($managementDays)));
        sort($properties);

        foreach ($properties as $propertyId) {
            $this->property($month, $start, $daysInMonth, $propertyId, $rentOverrides,
                $leaseDays[$propertyId] ?? [], $managementDays[$propertyId] ?? [], $firstLines, $charges, $forDecision);
        }

        return new MonthlyCharges($month, $charges, $forDecision);
    }

    /**
     * Свободните имоти за месеца (PROJECT.md, „Начисления, съдържание“, 21.11.2026):
     * имоти с действащ договор за управление (и „Собствен“), без наемател за
     * целия месец или за част от него. При фиксиран договор и дължимото към
     * собственика за свободните дни. Първо изцяло свободните, после по № на имот.
     *
     * @return list<Vacancy>
     */
    public function vacancies(int $companyId, string $month): array
    {
        if (! preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $month, $m)) {
            throw new InvalidArgumentException("Месецът трябва да е във вида гггг-мм: {$month}");
        }

        $start = CarbonImmutable::create((int) $m[1], (int) $m[2], 1)->startOfDay();
        $end = $start->addMonth();
        $daysInMonth = $start->daysInMonth;

        $leaseDays = $this->leaseDays($companyId, $start, $end);
        $vacancies = [];

        foreach ($this->managementDays($companyId, $start, $end) as $propertyId => $days) {
            $byContract = [];
            foreach ($days as $day => $row) {
                $byContract[$row['contract']][$day] = $row;
            }

            foreach ($byContract as $contractId => $contractDays) {
                $free = array_diff_key($contractDays, $leaseDays[$propertyId] ?? []);
                if ($free === []) {
                    continue;
                }

                $row = reset($contractDays);
                $vacancies[] = new Vacancy(
                    propertyId: $propertyId,
                    ownerContractId: $contractId,
                    ownerId: $row['owner'],
                    calculation: $row['calculation'],
                    freeDays: count($free),
                    firstDay: min(array_keys($free)),
                    lastDay: max(array_keys($free)),
                    wholeMonth: ($leaseDays[$propertyId] ?? []) === [],
                    ownerAmount: $row['calculation'] === CalculationMethod::Fixed->value
                        ? Cents::format(Cents::divide(array_sum(array_map(fn ($day) => $day['fixed'], $free)), $daysInMonth))
                        : null,
                );
            }
        }

        $numbers = DB::table('properties')->where('company_id', $companyId)->pluck('number', 'id');
        usort($vacancies, fn (Vacancy $a, Vacancy $b) => [! $a->wholeMonth, $numbers[$a->propertyId] ?? 0]
            <=> [! $b->wholeMonth, $numbers[$b->propertyId] ?? 0]);

        return $vacancies;
    }

    /**
     * Един имот за месеца.
     *
     * @param  array<int, array>  $lease  ден (0…) → договор за наем, наемател, наем
     * @param  array<int, array>  $management  ден (0…) → договор за управление, начин, сума, процент
     */
    private function property(string $month, CarbonImmutable $start, int $daysInMonth, int $propertyId, array $rentOverrides,
        array $lease, array $management, array $firstLines, array &$charges, array &$forDecision): void
    {
        // Към собственика при процент: наемът (разпределен по дни) и комисионата по договор за управление.
        $percentRent = [];
        $percentCommission = []; // числител върху (дни в месеца × 10000)
        $percentDays = [];

        $byLease = [];
        foreach ($lease as $day => $row) {
            $byLease[$row['contract']][$day] = $row;
        }

        foreach ($byLease as $contractId => $days) {
            $uncovered = count(array_diff_key($days, $management));
            if ($uncovered > 0) {
                $forDecision[] = new ChargeForDecision($month, $propertyId, $contractId, $uncovered);

                continue;
            }

            $weights = array_map(fn ($row) => $row['rent'], $days);
            $sum = array_sum($weights);
            $computed = Cents::divide($sum, $daysInMonth);

            // Договорен наем за първия период: само ако първият месец на имота в договора е непълен.
            $first = $firstLines[$contractId][$propertyId] ?? null;
            $agreed = $first !== null && $first['first_period_rent'] !== null
                && $first['from'] >= $start->toDateString() && $first['from'] < $start->addMonth()->toDateString()
                && count($days) < $daysInMonth;
            $amount = $agreed ? Cents::from($first['first_period_rent']) : $computed;

            // Ръчно променен наем в работната таблица: начислява се той, и дялът на собственика е от него.
            $override = $rentOverrides[$contractId.':'.$propertyId] ?? null;
            if ($override !== null) {
                $amount = Cents::from($override);
            }
            $fromAmount = $agreed || $override !== null;
            $this->guard($amount);

            $tenantId = reset($days)['tenant'];
            $charges[] = new ProposedCharge(ProposedCharge::RENT, $month, $propertyId, Cents::format($amount), count($days),
                leaseContractId: $contractId, tenantId: $tenantId, computedAmount: $agreed ? Cents::format($computed) : null);

            // Наемът по дни (в цели центове, сборът е точно начисленият), за да се раздели между договорите за управление.
            $shares = $this->allocate($amount, $weights);

            foreach ($days as $day => $row) {
                $owner = $management[$day];
                if ($owner['calculation'] !== CalculationMethod::Percent->value) {
                    continue;
                }

                $key = $owner['contract'];
                $percentRent[$key] = ($percentRent[$key] ?? 0) + $shares[$day];
                $percentDays[$key] = ($percentDays[$key] ?? 0) + 1;
                // Комисионата по части: от пресметнатия наем; от договорения, ако е ползван.
                $percentCommission[$key] = ($percentCommission[$key] ?? 0)
                    + ($fromAmount ? $shares[$day] * $daysInMonth : $row['rent']) * $owner['percent'];
            }
        }

        $byOwner = [];
        foreach ($management as $day => $row) {
            $byOwner[$row['contract']][$day] = $row;
        }

        foreach ($byOwner as $contractId => $days) {
            $row = reset($days);

            if ($row['calculation'] === CalculationMethod::Percent->value) {
                if (! isset($percentRent[$contractId])) {
                    continue; // свободен имот при процент: нищо
                }
                $commission = Cents::divide($percentCommission[$contractId], $daysInMonth * 10000);
                $amount = $percentRent[$contractId] - $commission;
                $count = $percentDays[$contractId];
            } elseif ($row['calculation'] === CalculationMethod::Fixed->value) {
                // По дните на договора за управление, независимо от наема.
                $amount = Cents::divide(array_sum(array_map(fn ($day) => $day['fixed'], $days)), $daysInMonth);
                $count = count($days);
            } else {
                continue; // собствен: нищо към собственик
            }

            $charges[] = new ProposedCharge(ProposedCharge::OWNER, $month, $propertyId, Cents::format($amount), $count,
                ownerContractId: $contractId, ownerId: $row['owner']);
        }
    }

    /**
     * Разделя сумата (в центове) по дни пропорционално на теглата, така че
     * сборът да е точно сумата (остатъкът отива в първите дни).
     *
     * @param  array<int, int>  $weights
     * @return array<int, int>
     */
    private function allocate(int $amount, array $weights): array
    {
        $total = array_sum($weights);
        if ($total === 0) {
            $weights = array_map(fn () => 1, $weights); // наем 0: по равно
            $total = count($weights);
        }

        $shares = array_map(fn ($weight) => intdiv($amount * $weight, $total), $weights);
        $rest = $amount - array_sum($shares);

        foreach (array_keys($shares) as $day) {
            if ($rest <= 0) {
                break;
            }
            $shares[$day]++;
            $rest--;
        }

        return $shares;
    }

    /** @return array<int, array<int, array{contract: int, tenant: int, rent: int}>> имот → ден → наем */
    private function leaseDays(int $companyId, CarbonImmutable $start, CarbonImmutable $end, ?array $onlyLeases = null): array
    {
        $rows = DB::table('lease_contract_lines as l')
            ->join('lease_contracts as c', fn ($join) => $join->on('c.id', '=', 'l.lease_contract_id')->on('c.company_id', '=', 'l.company_id'))
            ->where('l.company_id', $companyId)
            ->whereNull('c.archived_at')
            ->when($onlyLeases !== null, fn ($q) => $q->whereIn('l.lease_contract_id', $onlyLeases === [] ? [0] : $onlyLeases))
            ->where('l.valid_from', '<', $end->toDateString())
            ->where(fn ($q) => $q->whereNull('l.valid_to')->orWhere('l.valid_to', '>', $start->toDateString()))
            ->orderBy('l.valid_from')
            ->get(['l.lease_contract_id', 'l.property_id', 'l.valid_from', 'l.valid_to', 'l.rent', 'c.terminated_on', 'c.tenant_id']);

        $days = [];
        foreach ($rows as $row) {
            $rent = Cents::from($row->rent);
            $this->guard($rent);

            foreach ($this->dayIndexes($start, $end, $row->valid_from, $row->valid_to, $row->terminated_on) as $day) {
                $days[(int) $row->property_id][$day] = [
                    'contract' => (int) $row->lease_contract_id, 'tenant' => (int) $row->tenant_id, 'rent' => $rent,
                ];
            }
        }

        foreach ($days as &$propertyDays) {
            ksort($propertyDays);
        }

        return $days;
    }

    /** @return array<int, array<int, array{contract: int, owner: ?int, calculation: string, fixed: int, percent: int}>> */
    private function managementDays(int $companyId, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $rows = DB::table('owner_contract_lines as l')
            ->join('owner_contracts as c', fn ($join) => $join->on('c.id', '=', 'l.owner_contract_id')->on('c.company_id', '=', 'l.company_id'))
            ->where('l.company_id', $companyId)
            ->whereNull('c.archived_at')
            ->where('l.valid_from', '<', $end->toDateString())
            ->where(fn ($q) => $q->whereNull('l.valid_to')->orWhere('l.valid_to', '>', $start->toDateString()))
            ->orderBy('l.valid_from')
            ->get(['l.owner_contract_id', 'l.property_id', 'l.valid_from', 'l.valid_to', 'l.fixed_amount', 'c.calculation', 'c.terminated_on', 'c.owner_id']);

        $commissions = DB::table('owner_contract_commissions')
            ->where('company_id', $companyId)
            ->whereIn('owner_contract_id', $rows->pluck('owner_contract_id')->unique()->all())
            ->get(['owner_contract_id', 'valid_from', 'valid_to', 'percent'])
            ->groupBy('owner_contract_id');

        $days = [];
        foreach ($rows as $row) {
            $fixed = $row->fixed_amount === null ? 0 : Cents::from($row->fixed_amount);
            $this->guard($fixed);
            $percents = $this->percentByDay($start, $end, $commissions->get($row->owner_contract_id, collect())->all());

            foreach ($this->dayIndexes($start, $end, $row->valid_from, $row->valid_to, $row->terminated_on) as $day) {
                $days[(int) $row->property_id][$day] = [
                    'contract' => (int) $row->owner_contract_id,
                    'owner' => $row->owner_id === null ? null : (int) $row->owner_id,
                    'calculation' => $row->calculation,
                    'fixed' => $fixed,
                    'percent' => $percents[$day] ?? 0,
                ];
            }
        }

        return $days;
    }

    /** @return array<int, int> ден → процент в стотни (10,00% = 1000) */
    private function percentByDay(CarbonImmutable $start, CarbonImmutable $end, array $versions): array
    {
        $percents = [];
        foreach ($versions as $version) {
            foreach ($this->dayIndexes($start, $end, $version->valid_from, $version->valid_to, null) as $day) {
                $percents[$day] = Cents::from($version->percent);
            }
        }

        return $percents;
    }

    /**
     * Първият вариант на всеки имот във всеки договор за наем: начало и
     * договорен наем за първия период.
     *
     * @param  list<int>  $contractIds
     * @return array<int, array<int, array{from: string, first_period_rent: ?string}>>
     */
    private function firstLines(int $companyId, array $contractIds): array
    {
        $first = [];
        if ($contractIds === []) {
            return $first;
        }

        $rows = DB::table('lease_contract_lines')
            ->where('company_id', $companyId)
            ->whereIn('lease_contract_id', $contractIds)
            ->orderBy('valid_from')->orderBy('id')
            ->get(['lease_contract_id', 'property_id', 'valid_from', 'first_period_rent']);

        foreach ($rows as $row) {
            $first[(int) $row->lease_contract_id][(int) $row->property_id] ??= [
                'from' => substr($row->valid_from, 0, 10), 'first_period_rent' => $row->first_period_rent,
            ];
        }

        return $first;
    }

    /**
     * Дните от месеца (0 = първо число) в периода [от, до): краят е по-ранният
     * от „до“ на реда и прекратяването на договора, и двата без него.
     *
     * @return list<int>
     */
    private function dayIndexes(CarbonImmutable $start, CarbonImmutable $end, string $from, ?string $to, ?string $terminated): array
    {
        $from = max(CarbonImmutable::parse($from)->startOfDay(), $start);
        $until = $end;
        foreach ([$to, $terminated] as $limit) {
            if ($limit !== null) {
                $until = min($until, CarbonImmutable::parse($limit)->startOfDay());
            }
        }

        if ($from >= $until) {
            return [];
        }

        return range((int) $start->diffInDays($from), (int) $start->diffInDays($until) - 1);
    }

    private function guard(int $cents): void
    {
        if ($cents > self::MAX_CENTS) {
            throw new LogicException('Сума над 1 000 000 € на месец не се смята.');
        }
    }
}
