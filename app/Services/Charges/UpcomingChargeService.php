<?php

namespace App\Services\Charges;

use App\Enums\ChargeSource;
use App\Enums\ChargeType;
use App\Models\ChargeDraft;
use App\Models\LeaseContract;
use App\Models\Property;
use App\Models\UpcomingCharge;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Предстоящи начисления (PROJECT.md, т. 2 „Начисления“): депозити, неустойки
 * и други еднократни суми, въведени през месеца, когато станат известни, за
 * своя месец. Вноските от депозит се въвеждат така за бъдещи месеци, а
 * deposit() показва колко остава до договорения размер.
 */
class UpcomingChargeService
{
    /**
     * @param  array{lease_contract_id: int, property_id: int, type: string, month: string, amount: string, note?: ?string}  $data
     */
    public function create(array $data): UpcomingCharge
    {
        $input = new ChargeInput;

        $type = ChargeType::tryFrom((string) ($data['type'] ?? ''));
        if ($type === null || ! $type->canBeUpcoming()) {
            $input->fail('type', 'type_not_upcoming');
        }
        $lease = $input->leaseWithProperty($data['lease_contract_id'] ?? null, $data['property_id'] ?? null);
        [$month, $amount, $note] = $this->values($input, $data, $type);
        $input->check();

        $upcoming = new UpcomingCharge;
        $upcoming->forceFill([
            'lease_contract_id' => $lease->id,
            'property_id' => (int) $data['property_id'],
            'type' => $type,
            'month' => $month,
            'amount' => $amount,
            'note' => $note,
        ])->save();

        return $upcoming;
    }

    /** Промяна на месеца, сумата и бележката (видът и договорът остават). */
    public function update(UpcomingCharge $upcoming, array $data): UpcomingCharge
    {
        $input = new ChargeInput;
        if ($upcoming->cancelled_at !== null) {
            $input->fail('changes', 'upcoming_cancelled');
            $input->check();
        }

        [$month, $amount, $note] = $this->values($input, $data, $upcoming->type);
        $input->check();

        $upcoming->forceFill(['month' => $month, 'amount' => $amount, 'note' => $note])->save();

        return $upcoming;
    }

    /** Отказ (вместо изтриване), с причина. Неизпълненият ред в работната таблица отпада при „Генерирай отново“. */
    public function cancel(UpcomingCharge $upcoming, ?string $reason): UpcomingCharge
    {
        $input = new ChargeInput;
        $reason = $input->reason($reason);
        $input->check();

        $upcoming->forceFill(['cancelled_at' => now(), 'cancel_reason' => $reason])->save();

        return $upcoming;
    }

    /**
     * Депозитът по договора за имота: договорен (сегашният вариант на реда),
     * въведен (предстоящите вноски, без отказаните, и ръчните редове) и остатък.
     *
     * @return array{contracted: string, entered: string, remaining: string}
     */
    public function deposit(LeaseContract $lease, Property|int $property): array
    {
        $propertyId = $property instanceof Property ? $property->id : $property;

        $contracted = Cents::from((string) ($lease->lines()->where('property_id', $propertyId)
            ->orderByDesc('valid_from')->value('deposit') ?? '0.00'));

        $entered = UpcomingCharge::query()
            ->where('lease_contract_id', $lease->id)->where('property_id', $propertyId)
            ->where('type', ChargeType::Deposit)->whereNull('cancelled_at')
            ->pluck('amount')
            ->merge(ChargeDraft::query()
                ->where('lease_contract_id', $lease->id)->where('property_id', $propertyId)
                ->where('type', ChargeType::Deposit)->where('source', ChargeSource::Manual)->whereNull('removed_at')
                ->pluck('amount'))
            ->sum(fn ($amount) => Cents::from((string) $amount));

        return [
            'contracted' => Cents::format($contracted),
            'entered' => Cents::format($entered),
            'remaining' => Cents::format($contracted - $entered),
        ];
    }

    /** @return array{0: mixed, 1: ?string, 2: ?string} месец, сума, бележка */
    private function values(ChargeInput $input, array $data, ?ChargeType $type): array
    {
        $month = Month::tryParse($data['month'] ?? null);
        if ($month === null) {
            $input->fail('month', 'month_invalid');
        }

        return [
            $month,
            $input->amount($data['amount'] ?? null, positive: true),
            $input->note($data['note'] ?? null, required: $type?->needsNote() ?? false),
        ];
    }
}
