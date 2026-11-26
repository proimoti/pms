@extends('layouts.app')

@use('App\Support\Format')
@use('App\Services\Charges\Month')

@section('title', __('reports.payouts.title'))
@section('width', 'list')

@section('content')
    <h1>{{ __('reports.payouts.title') }} — {{ Month::label($month) }}</h1>
    <p class="muted">{{ __('reports.payouts.intro') }}</p>

    <form method="GET" action="/reports/payouts" class="row" style="justify-content: flex-start; align-items: flex-end;">
        <div>
            <label for="month">{{ __('reports.payouts.month') }}</label>
            <input id="month" type="text" name="month" value="{{ $month->format('m.Y') }}" placeholder="12.2026" style="max-width: 120px;">
        </div>
        <button type="submit" class="secondary">{{ __('reports.show') }}</button>
        <a class="button" style="margin-top: 0;" href="/reports/payouts?{{ http_build_query(['month' => $month->format('m.Y'), 'export' => 'xlsx']) }}">{{ __('reports.download') }}</a>
    </form>

    @if ($data['groups'] === [])
        <p class="muted">{{ __('reports.payouts.empty') }}</p>
    @else
        @unless ($data['issued'])
            <div class="warning">{{ __('reports.payouts.not_issued') }}</div>
        @endunless
        @php($c = 'reports.payouts.columns.')
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>{{ __($c.'beneficiary') }}</th><th>{{ __($c.'payment_method') }}</th><th>{{ __($c.'iban') }}</th>
                    <th>{{ __($c.'contract') }}</th><th>{{ __($c.'owner') }}</th><th>{{ __($c.'properties') }}</th>
                    <th class="num">{{ __('reports.payouts.amount') }}</th><th class="num">{{ __('payouts.report.paid') }}</th><th class="num">{{ __('payouts.report.left') }}</th><th>{{ __($c.'note') }}</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($data['groups'] as $group)
                    @foreach ($group['rows'] as $row)
                        <tr>
                            <td>
                                @if ($loop->first)
                                    @if ($group['beneficiary'])<a href="/beneficiaries/{{ $group['beneficiary']->id }}">{{ $group['name'] }}</a>@else {{ $group['name'] }} @endif
                                @endif
                            </td>
                            <td>@if ($loop->first && $group['method']){{ __('contracts.payment_methods.'.$group['method']) }}@endif</td>
                            <td>
                                @if ($loop->first)
                                    @if ($group['iban']) {{ $group['iban'] }} @elseif ($group['missing_iban']) <span class="warning" style="display: inline-block; margin: 0; padding: 2px 8px;">{{ __('reports.payouts.missing_iban') }}</span> @endif
                                @endif
                            </td>
                            <td><a href="/owner-contracts/{{ $row['contract']->id }}">№ {{ $row['contract']->number }}</a></td>
                            <td>@if ($row['owner'])<a href="/owners/{{ $row['owner']->id }}">{{ $row['owner']->name }}</a>@endif</td>
                            <td>@include('reports.properties', ['properties' => $row['properties']])</td>
                            <td class="num">{{ Format::money($row['amount']) }}</td>
                            <td class="num">{{ Format::money($row['paid']) }}</td>
                            <td class="num">{{ Format::money($row['left']) }}</td>
                            <td>
                                @if ($row['changed']){{ __('reports.payouts.changed') }}@endif
                                @if ($canPay && (float) $row['left'] > 0)
                                    <a class="button secondary" style="margin-top: 0;" href="/owner-contracts/{{ $row['contract']->id }}/payouts/create?amount={{ $row['left'] }}">{{ __('payouts.report.button') }}</a>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    <tr>
                        <th colspan="6">{{ __('reports.payouts.total') }}: {{ $group['name'] }}</th>
                        <th class="num">{{ Format::money($group['total']) }}</th>
                        <th colspan="3"></th>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif
@endsection
