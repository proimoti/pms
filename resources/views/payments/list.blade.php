{{-- Плащанията по договор за наем (26.11.2026): сторнираните остават видими, с причината. --}}
@use('App\Support\Format')
@php($f = 'payments.fields.')
<h2>{{ __('payments.section') }}</h2>
@if ($canPay)
    <p><a class="button secondary" style="margin-top: 0;" href="/lease-contracts/{{ $contract->id }}/payments/create">{{ __('payments.new') }}</a></p>
@endif
@if ($payments->isEmpty())
    <p class="muted">{{ __('payments.none') }}</p>
@else
    <div class="table-wrap">
        <table>
            <thead>
            <tr>
                <th>{{ __($f.'paid_on') }}</th><th class="num">{{ __($f.'amount') }}</th><th>{{ __($f.'method') }}</th>
                <th>{{ __($f.'reference') }}</th><th>{{ __($f.'created_by') }}</th><th>{{ __($f.'status') }}</th>
            </tr>
            </thead>
            <tbody>
            @foreach ($payments as $payment)
                <tr @class(['removed' => $payment->reversal !== null])>
                    <td class="nowrap">{{ $payment->paid_on->format('d.m.Y') }}</td>
                    <td class="num">{{ Format::money($payment->amount) }}</td>
                    <td>{{ __('payments.methods.'.$payment->method) }}@if ($payment->bankAccount) <div class="muted">{{ $payment->bankAccount->formattedIban() }}</div>@endif</td>
                    <td>{{ $payment->reference }}@if ($payment->note) <div class="muted">{{ $payment->note }}</div>@endif</td>
                    <td>{{ $payment->createdBy?->name }}</td>
                    <td>
                        @if ($payment->reversal)
                            <span class="badge state-archived">{{ __('payments.reversed') }}</span>
                            <div class="muted">{{ $payment->reversal->reason }} · {{ $payment->reversal->created_at?->format('d.m.Y') }}</div>
                        @elseif ($canPay)
                            <form method="POST" action="/payments/{{ $payment->id }}/reverse" class="actions" onsubmit="return confirm(@js(__('payments.reverse_confirm')))">
                                @csrf
                                <input type="text" name="reason" placeholder="{{ __('payments.reverse_reason') }}" aria-label="{{ __('payments.reverse_reason') }}" required style="max-width: 220px;">
                                <button type="submit" class="secondary" style="margin-top: 0;">{{ __('payments.reverse') }}</button>
                            </form>
                        @endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
@endif
