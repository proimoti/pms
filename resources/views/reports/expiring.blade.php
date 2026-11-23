@extends('layouts.app')

@use('App\Support\Format')

@section('title', __('reports.expiring.title'))
@section('width', 'full')

@section('content')
    <h1>{{ __('reports.expiring.title') }}</h1>
    <p class="muted">{{ __('reports.expiring.intro') }}</p>

    <form method="GET" action="/reports/expiring" class="row" style="justify-content: flex-start; align-items: flex-end;">
        <div>
            <label for="days">{{ __('reports.expiring.days') }}</label>
            <input id="days" type="number" name="days" min="1" max="365" value="{{ $days }}" style="max-width: 120px;">
        </div>
        <button type="submit" class="secondary">{{ __('reports.show') }}</button>
        <a class="button" style="margin-top: 0;" href="/reports/expiring?{{ http_build_query(['days' => $days, 'export' => 'xlsx']) }}">{{ __('reports.download') }}</a>
    </form>

    @if ($rows === [])
        <p class="muted">{{ __('reports.empty') }}</p>
    @else
        @php($c = 'reports.expiring.columns.')
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>{{ __($c.'contract') }}</th><th>{{ __($c.'properties') }}</th><th>{{ __($c.'tenant') }}</th>
                    <th>{{ __($c.'owners') }}</th><th>{{ __($c.'end_on') }}</th><th class="num">{{ __($c.'days_left') }}</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($rows as $row)
                    <tr>
                        <td><a href="/lease-contracts/{{ $row['contract']->id }}">№ {{ $row['contract']->number }}</a></td>
                        <td>@include('reports.properties', ['properties' => $row['properties']])</td>
                        <td>
                            @if ($row['contract']->tenant)
                                <a href="/tenants/{{ $row['contract']->tenant->id }}">{{ $row['contract']->tenant->name }}</a>
                                <div class="muted">{{ $row['contract']->tenant->phone }} {{ $row['contract']->tenant->email }}</div>
                            @endif
                        </td>
                        <td>
                            @foreach ($row['owners'] as $owner)
                                <div><a href="/owners/{{ $owner->id }}">{{ $owner->name }}</a> <span class="muted">{{ $owner->phone }} {{ $owner->email }}</span></div>
                            @endforeach
                        </td>
                        <td>{{ Format::date($row['end_on']) }}</td>
                        <td class="num">{{ $row['days'] }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif
@endsection
