@extends('layouts.app')

@use('App\Support\Format')
@use('App\Services\Charges\Month')

@section('title', __('registry.one.funds').' '.$fund->name)
@section('width', 'wide')

@section('content')
    <h1>{{ __('registry.one.funds') }} {{ $fund->name }}</h1>

    @if ($fund->missingAnalytic())
        <div class="warning">{{ __('utilities.funds.missing_analytic') }}</div>
    @endif

    <dl>
        <dt>{{ __('utilities.funds.fields.analytic') }}</dt><dd>{{ $fund->analytic ?? __('registry.none') }}</dd>
        <dt>{{ __('utilities.funds.fields.properties') }}</dt>
        <dd>
            @foreach ($fund->properties as $line)
                @if ($line->property)
                    <a href="/properties/{{ $line->property_id }}">№ {{ $line->property->number }}</a> {{ \App\Support\Address::format($line->property) }}@if (! $loop->last)<br>@endif
                @endif
            @endforeach
        </dd>
        <dt>{{ __('utilities.funds.fields.note') }}</dt><dd style="white-space: pre-line;">{{ $fund->note ?? __('registry.none') }}</dd>
    </dl>

    <h2>{{ __('utilities.funds.report') }}</h2>
    <form method="GET" action="/funds/{{ $fund->id }}" class="row" style="justify-content: flex-start; align-items: flex-end;">
        <div>
            <label for="from">{{ __('utilities.funds.from') }}</label>
            <input id="from" type="text" name="from" value="{{ $from->format('m.Y') }}" placeholder="12.2026" style="max-width: 120px;">
        </div>
        <div>
            <label for="to">{{ __('utilities.funds.to') }}</label>
            <input id="to" type="text" name="to" value="{{ $to->format('m.Y') }}" placeholder="12.2026" style="max-width: 120px;">
        </div>
        <button type="submit" class="secondary">{{ __('utilities.funds.show') }}</button>
    </form>
    <p class="muted">{{ __('utilities.funds.month_hint') }}</p>

    <div class="table-wrap">
        <table>
            <thead>
            <tr>
                <th></th>
                <th class="num">{{ __('utilities.funds.collected') }}</th>
                <th class="num">{{ __('utilities.funds.paid') }}</th>
                <th class="num">{{ __('utilities.funds.left') }}</th>
            </tr>
            </thead>
            <tbody>
            <tr>
                <td>{{ __('utilities.funds.period') }}: {{ Month::label($from) }} – {{ Month::label($to) }}</td>
                <td class="num">{{ Format::money($period->collected) }} €</td>
                <td class="num">{{ Format::money($period->paid) }} €</td>
                <td class="num">{{ Format::money($period->left) }} €</td>
            </tr>
            <tr>
                <th>{{ __('utilities.funds.total') }}</th>
                <th class="num">{{ Format::money($total->collected) }} €</th>
                <th class="num">{{ Format::money($total->paid) }} €</th>
                <th class="num">{{ Format::money($total->left) }} €</th>
            </tr>
            </tbody>
        </table>
    </div>

    <h3>{{ __('utilities.funds.entries') }}</h3>
    @if ($entries->isEmpty())
        <p class="muted">{{ __('utilities.funds.no_entries') }}</p>
    @else
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>{{ __('charges.fields.month') }}</th>
                    <th>{{ __('charges.issue.entry_date') }}</th>
                    <th>{{ __('charges.fields.type') }}</th>
                    <th class="num">{{ __('charges.fields.amount') }}</th>
                    <th>{{ __('charges.fields.note') }}</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($entries as $entry)
                    <tr>
                        <td>{{ Month::label($entry->month) }}</td>
                        <td>{{ Format::date($entry->entry_date) }}</td>
                        <td>{{ __('utilities.funds.kinds.'.$entry->kind->value) }}</td>
                        <td class="num">{{ Format::money($entry->amount) }}</td>
                        <td>
                            @if ($entry->paidUtilityBill)
                                {{ $entry->paidUtilityBill->provider_name }} · № {{ $entry->paidUtilityBill->file_number }} · {{ Format::date($entry->paidUtilityBill->paid_at) }}
                            @endif
                            {{ $entry->reason }}
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif

    <h2>{{ __('utilities.funds.accounts') }}</h2>
    @if ($accounts->isEmpty())
        <p class="muted">{{ __('registry.empty') }}</p>
    @else
        <ul>
            @foreach ($accounts as $account)
                <li>
                    <a href="/utility-accounts/{{ $account->id }}">{{ __('registry.one.utility-accounts') }} {{ $account->number ?? '' }}</a>
                    · {{ __('utilities.kinds.'.$account->kind->value) }}
                    · {{ $account->isFixed() ? __('utilities.billing.fixed').' '.Format::money($account->fixed_amount).' €' : __('utilities.fields.paid_from_fund') }}
                    @if (filled($account->note)) · {{ $account->note }} @endif
                    @if ($account->isArchived()) <span class="muted">({{ __('registry.is_archived') }})</span> @endif
                </li>
            @endforeach
        </ul>
    @endif

    @include('registry.partials.record-actions', ['section' => 'funds', 'record' => $fund])
@endsection
