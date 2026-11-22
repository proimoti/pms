{{-- Текстът на битов ред (PROJECT.md, „Битови сметки в месеца“): вид, доставчик, номер и период;
     при фиксиран абонат — вид, бележката на абоната и месецът на таксата. $row: ред от работната
     таблица; $details: да се покаже ли „Подробно описание“ от ePay (в известието). --}}
@php($account = $row->utilityAccount)
@if ($row->type === \App\Enums\ChargeType::Utility)
    {{ __('utilities.kinds.'.$account?->kind?->value) }}
    · {{ $account?->provider?->name }}
    · № {{ $account?->number }}
    · {{ __('charges.screens.utility_period', ['from' => $row->period_from?->format('d.m'), 'to' => $row->period_to?->format('d.m.Y')]) }}
    @if (($details ?? false) && $row->paidUtilityBill?->source === 'epay' && filled($row->paidUtilityBill?->details))
        <div class="muted" style="white-space: pre-line;">{{ $row->paidUtilityBill->details }}</div>
    @endif
@else
    {{ __('utilities.kinds.'.$account?->kind?->value) }}@if (filled($account?->note)) · {{ $account->note }}@endif
    · {{ __('charges.screens.fee_for_month', ['month' => \App\Services\Charges\Month::label($row->period_from)]) }}
@endif
