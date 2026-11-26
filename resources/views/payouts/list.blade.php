{{-- Изплащания по договор за управление (26.11.2026): начислено, изплатено, остава и списъкът; сторнираните остават видими. --}}
@use('App\Support\Format')
@use('App\Services\Charges\Cents')
@php($f = 'payments.fields.')
<h2>{{ __('payouts.section') }}</h2>
<dl>
    <dt>{{ __('payouts.summary.accrued') }}</dt><dd>{{ Format::money(Cents::format($state['accrued'])) }} €</dd>
    <dt>{{ __('payouts.summary.paid') }}</dt><dd>{{ Format::money(Cents::format($state['paid_out'])) }} €</dd>
    <dt>{{ __('payouts.summary.left') }}</dt><dd><strong>@include('dossiers.owes-amount', ['amount' => Cents::format($state['accrued'] - $state['paid_out'])])</strong></dd>
</dl>
@if ($canPay)
    <p><a class="button secondary" style="margin-top: 12px;" href="/owner-contracts/{{ $contract->id }}/payouts/create">{{ __('payouts.new') }}</a></p>
@endif
@if ($payouts->isEmpty())
    <p class="muted">{{ __('payouts.none') }}</p>
@else
    <div class="table-wrap">
        <table>
            <thead>
            <tr>
                <th>{{ __($f.'paid_on') }}</th><th class="num">{{ __($f.'amount') }}</th><th>{{ __('payouts.payee') }}</th><th>{{ __($f.'method') }}</th>
                <th>{{ __($f.'note') }}</th><th>{{ __($f.'created_by') }}</th><th>{{ __($f.'status') }}</th>
            </tr>
            </thead>
            <tbody>
            @foreach ($payouts as $payout)
                <tr @class(['removed' => $payout->reversal !== null])>
                    <td class="nowrap">{{ $payout->paid_on->format('d.m.Y') }}</td>
                    <td class="num">{{ Format::money($payout->amount) }}</td>
                    <td>{{ $payout->beneficiary?->name }}</td>
                    <td>{{ __('payments.methods.'.$payout->method) }}@if ($payout->bankAccount) <div class="muted">{{ $payout->bankAccount->formattedIban() }}</div>@endif</td>
                    <td>{{ $payout->note }}</td>
                    <td>{{ $payout->createdBy?->name }}</td>
                    <td>
                        @if ($payout->reversal)
                            <span class="badge state-archived">{{ __('payments.reversed') }}</span>
                            <div class="muted">{{ $payout->reversal->reason }} · {{ $payout->reversal->created_at?->format('d.m.Y') }}</div>
                        @elseif ($canPay)
                            <form method="POST" action="/payouts/{{ $payout->id }}/reverse" class="actions" onsubmit="return confirm(@js(__('payouts.reverse_confirm')))">
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
