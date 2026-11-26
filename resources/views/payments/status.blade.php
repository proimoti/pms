{{-- Платено ли е известието (26.11.2026): $info = ['amount' => центове, 'paid' => центове] или null. --}}
@if ($info === null || $info['amount'] <= 0)
    <span class="muted">{{ __('registry.none') }}</span>
@elseif ($info['paid'] >= $info['amount'])
    <span class="badge state-active">{{ __('payments.status.paid') }}</span>
@elseif ($info['paid'] > 0)
    <span class="badge" style="background: #fdf2dc; color: var(--warn);">{{ __('payments.status.partly', ['paid' => \App\Support\Format::money(\App\Services\Charges\Cents::format($info['paid'])), 'amount' => \App\Support\Format::money(\App\Services\Charges\Cents::format($info['amount']))]) }}</span>
@else
    <span class="badge state-archived">{{ __('payments.status.unpaid') }}</span>
@endif
