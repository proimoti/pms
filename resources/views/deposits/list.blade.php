{{-- Депозитът по договор за наем (26.11.2026; таблица на движенията 27.11.2026): всички движения по 491
     с ясни надписи, държаният, внесеният и приключванията; сторнираните остават видими. --}}
@use('App\Support\Format')
@use('App\Services\Charges\Cents')
@php($numbers = $contract->lines->pluck('property.number', 'property_id'))
<section id="deposit-ledger">
<h2>{{ __('deposits.section') }}</h2>
@if ($entries->isEmpty())
    <p class="muted">{{ __('charges.issue.no_entries') }}</p>
@else
    <div class="table-wrap">
        <table>
            <thead>
            <tr>
                <th>{{ __('charges.fields.month') }}</th>
                <th>{{ __('charges.issue.entry_date') }}</th>
                <th>{{ __('deposits.fields.property') }}</th>
                <th>{{ __('deposits.movement') }}</th>
                <th class="num">{{ __('charges.fields.amount') }}</th>
                <th>{{ __('charges.fields.reason') }}</th>
            </tr>
            </thead>
            <tbody>
            @foreach ($entries as $entry)
                @php($movement = __('deposits.movements.'.$entry->kind->value))
                <tr>
                    <td>{{ \App\Services\Charges\Month::label($entry->month) }}</td>
                    <td>{{ Format::date($entry->entry_date) }}</td>
                    <td>@if ($entry->property_id)№ {{ $numbers[$entry->property_id] ?? '?' }}@endif</td>
                    <td>{{ in_array($entry->deposit_closing_id, $closingReversals, true) ? __('deposits.movements.reversal', ['movement' => $movement]) : $movement }}</td>
                    <td class="num">{{ Format::money($entry->amount) }}</td>
                    <td>{{ $entry->reason }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
@endif
<p>{{ __('deposits.paid') }}: <strong>{{ Format::money(Cents::format($paid)) }} €</strong>
    · {{ __('deposits.held') }}: <strong>{{ Format::money(Cents::format($held)) }} €</strong></p>
@if ($canPay && $held > 0)
    <p><a class="button secondary" style="margin-top: 0;" href="/lease-contracts/{{ $contract->id }}/deposit-closings/create">{{ __('deposits.new') }}</a></p>
@endif
@if ($closings->isNotEmpty())
    <div class="table-wrap">
        <table>
            <thead>
            <tr>
                <th>{{ __('deposits.fields.closed_on') }}</th>
                @foreach (array_keys(\App\Services\Payments\DepositClosingService::METHODS) as $method)<th class="num">{{ __('deposits.methods.'.$method) }}</th>@endforeach
                <th>{{ __('deposits.fields.note') }}</th><th>{{ __('payments.fields.created_by') }}</th><th>{{ __('payments.fields.status') }}</th>
            </tr>
            </thead>
            <tbody>
            @foreach ($closings as $closing)
                @php($sums = $closing->entries->where('account', \App\Enums\LedgerAccount::Deposit)->groupBy(fn ($e) => $e->kind->value)->map(fn ($g) => -$g->sum(fn ($e) => Cents::from((string) $e->amount))))
                <tr @class(['removed' => $closing->reversal !== null])>
                    <td class="nowrap">{{ $closing->closed_on->format('d.m.Y') }}</td>
                    @foreach (\App\Services\Payments\DepositClosingService::METHODS as $method => $kind)
                        <td class="num">{{ isset($sums[$kind->value]) ? Format::money(Cents::format($sums[$kind->value])) : '' }}
                            @if ($method === 'refund' && isset($sums[$kind->value]) && $closing->refund_method)<div class="muted">{{ __('payments.methods.'.$closing->refund_method) }}</div>@endif
                        </td>
                    @endforeach
                    <td>{{ $closing->note }}</td>
                    <td>{{ $closing->createdBy?->name }}</td>
                    <td>
                        @if ($closing->reversal)
                            <span class="badge state-archived">{{ __('deposits.reversed') }}</span>
                            <div class="muted">{{ $closing->reversal->reason }} · {{ $closing->reversal->created_at?->format('d.m.Y') }}</div>
                        @elseif ($canPay)
                            <form method="POST" action="/deposit-closings/{{ $closing->id }}/reverse" class="actions" onsubmit="return confirm(@js(__('deposits.reverse_confirm')))">
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
</section>
