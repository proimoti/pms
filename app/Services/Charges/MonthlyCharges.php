<?php

namespace App\Services\Charges;

use App\Models\LeaseContract;
use App\Models\OwnerContract;
use App\Models\Property;

/**
 * Резултатът от сметката за един месец: предложените начисления и списъкът
 * „за решение“. Приходът на фирмата не се пази, а се смята като разлика
 * (наем − дължимото към собственика; PROJECT.md, „Начисления, уточнения“).
 */
final class MonthlyCharges
{
    /**
     * @param  list<ProposedCharge>  $charges
     * @param  list<ChargeForDecision>  $forDecision
     */
    public function __construct(
        public readonly string $month,
        private readonly array $charges,
        private readonly array $forDecision,
    ) {}

    /** @return list<ProposedCharge> */
    public function charges(): array
    {
        return $this->charges;
    }

    /** @return list<ChargeForDecision> */
    public function forDecision(): array
    {
        return $this->forDecision;
    }

    /** Наемът за имота (по желание за даден договор за наем). */
    public function rent(Property|int $property, ?LeaseContract $lease = null): ?ProposedCharge
    {
        return $this->first(ProposedCharge::RENT, $property, fn (ProposedCharge $c) => $lease === null || $c->leaseContractId === $lease->id);
    }

    /** Дължимото към собственика за имота (по желание по даден договор за управление). */
    public function ownerPayable(Property|int $property, ?OwnerContract $contract = null): ?ProposedCharge
    {
        return $this->first(ProposedCharge::OWNER, $property, fn (ProposedCharge $c) => $contract === null || $c->ownerContractId === $contract->id);
    }

    /** Приходът на фирмата от имота за месеца: наемът − дължимото към собственика. Може да е отрицателен. */
    public function revenue(Property|int $property): string
    {
        $id = $property instanceof Property ? $property->id : $property;
        $cents = 0;

        foreach ($this->charges as $charge) {
            if ($charge->propertyId === $id) {
                $cents += ($charge->type === ProposedCharge::RENT ? 1 : -1) * Cents::from($charge->amount);
            }
        }

        return Cents::format($cents);
    }

    private function first(string $type, Property|int $property, callable $match): ?ProposedCharge
    {
        $id = $property instanceof Property ? $property->id : $property;

        foreach ($this->charges as $charge) {
            if ($charge->type === $type && $charge->propertyId === $id && $match($charge)) {
                return $charge;
            }
        }

        return null;
    }
}
