@extends('layouts.app')

@use('App\Support\Format')
@use('App\Services\Charges\Month')

@section('title', __('reports.settlements.title'))
@section('width', 'list')

@section('content')
    <h1>{{ __('reports.settlements.title') }}</h1>
    <p class="muted">{{ __('reports.settlements.intro') }}</p>

    <form method="GET" action="/reports/settlements" class="row" style="justify-content: flex-start; align-items: flex-end;">
        <div>
            <label for="from">{{ __('reports.settlements.from') }}</label>
            <input id="from" type="text" name="from" value="{{ $from->format('m.Y') }}" placeholder="12.2026" style="max-width: 120px;">
        </div>
        <div>
            <label for="to">{{ __('reports.settlements.to') }}</label>
            <input id="to" type="text" name="to" value="{{ $to->format('m.Y') }}" placeholder="12.2026" style="max-width: 120px;">
        </div>
        <button type="submit" class="secondary">{{ __('reports.show') }}</button>
        <a class="button" style="margin-top: 0;" href="/reports/settlements?{{ http_build_query(['from' => $from->format('m.Y'), 'to' => $to->format('m.Y'), 'export' => 'xlsx']) }}">{{ __('reports.download') }}</a>
    </form>

    @if ($data['not_issued'] !== [])
        <div class="warning" style="margin-top: 14px;">{{ __('reports.settlements.not_issued', ['months' => implode(', ', array_map(fn ($m) => Month::label($m), $data['not_issued']))]) }}</div>
    @endif

    @if ($data['rows'] === [])
        <p class="muted">{{ __('reports.settlements.empty') }}</p>
    @else
        @php($c = 'reports.settlements.columns.')
        <div class="table-wrap" style="margin-top: 14px;">
            <table>
                <thead>
                <tr>
                    <th>{{ __($c.'property') }}</th><th>{{ __($c.'address') }}</th><th>{{ __($c.'lease') }}</th><th>{{ __($c.'tenant') }}</th>
                    <th>{{ __($c.'management') }}</th><th>{{ __($c.'owner') }}</th><th>{{ __($c.'broker') }}</th>
                    <th class="num">{{ __($c.'monthly_rent') }}</th><th class="num">{{ __($c.'rent') }}</th>
                    <th class="num">{{ __($c.'owner_payable') }}</th><th class="num">{{ __($c.'result') }}</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($data['rows'] as $row)
                    <tr id="settlement-{{ $row['property']->id }}">
                        <td class="nowrap"><a href="/properties/{{ $row['property']->id }}">№ {{ $row['property']->number }}</a></td>
                        <td>{{ \App\Support\Address::format($row['property']) }}</td>
                        <td class="nowrap">@foreach ($row['leases'] as $lease)<a href="/lease-contracts/{{ $lease->id }}">№ {{ $lease->number }}</a>@if (! $loop->last), @endif @endforeach</td>
                        <td>@foreach ($row['leases']->pluck('tenant')->filter()->unique('id') as $tenant)<a href="/tenants/{{ $tenant->id }}">{{ $tenant->name }}</a>@if (! $loop->last), @endif @endforeach</td>
                        <td class="nowrap">@foreach ($row['managements'] as $management)<a href="/owner-contracts/{{ $management->id }}">№ {{ $management->number }}</a>@if (! $loop->last), @endif @endforeach</td>
                        <td>@foreach ($row['managements'] as $management)@if ($management->owner)<a href="/owners/{{ $management->owner->id }}">{{ $management->owner->name }}</a>@else{{ __('contracts.screens.own_company') }}@endif @if (! $loop->last), @endif @endforeach</td>
                        <td>@if ($row['property']->broker)<a href="/brokers/{{ $row['property']->broker->id }}">{{ $row['property']->broker->name }}</a>@endif</td>
                        <td class="num">{{ $row['monthly']->map(fn ($m) => Format::money($m))->implode(', ') }}</td>
                        <td class="num">{{ Format::money($row['rent']) }}</td>
                        <td class="num">{{ Format::money($row['owner']) }}</td>
                        <td class="num">{{ Format::money($row['result']) }}</td>
                    </tr>
                @endforeach
                </tbody>
                <tfoot>
                <tr>
                    <th colspan="8">{{ __('reports.settlements.total') }}</th>
                    <td class="num">{{ Format::money($data['totals']['rent']) }}</td>
                    <td class="num">{{ Format::money($data['totals']['owner']) }}</td>
                    <td class="num">{{ Format::money($data['totals']['result']) }}</td>
                </tr>
                </tfoot>
            </table>
        </div>
    @endif
@endsection
