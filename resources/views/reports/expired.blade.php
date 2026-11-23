@extends('layouts.app')

@use('App\Support\Format')

@section('title', __('reports.expired.title'))
@section('width', 'full')

@section('content')
    <h1>{{ __('reports.expired.title') }}</h1>
    <p class="muted">{{ __('reports.expired.note') }}</p>

    <form method="GET" action="/reports/expired" class="row" style="justify-content: flex-start; align-items: flex-end;">
        <div>
            <label for="kind">{{ __('reports.expired.kind') }}</label>
            <select id="kind" name="kind">
                <option value="">{{ __('reports.expired.all') }}</option>
                @foreach (['lease', 'owner'] as $option)
                    <option value="{{ $option }}" @selected($kind === $option)>{{ __('reports.expired.kinds.'.$option) }}</option>
                @endforeach
            </select>
        </div>
        <button type="submit" class="secondary">{{ __('reports.show') }}</button>
        <a class="button" style="margin-top: 0;" href="/reports/expired?{{ http_build_query(array_filter(['kind' => $kind, 'export' => 'xlsx'])) }}">{{ __('reports.download') }}</a>
    </form>

    @if ($rows === [])
        <p class="muted">{{ __('reports.empty') }}</p>
    @else
        @php($c = 'reports.expired.columns.')
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>{{ __($c.'kind') }}</th><th>{{ __($c.'contract') }}</th><th>{{ __($c.'party') }}</th>
                    <th>{{ __($c.'properties') }}</th><th>{{ __($c.'end_on') }}</th><th class="num">{{ __($c.'days_after') }}</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($rows as $row)
                    <tr>
                        <td>{{ __('reports.expired.kinds.'.$row['kind']) }}</td>
                        <td><a href="/{{ $row['kind'] === 'lease' ? 'lease-contracts' : 'owner-contracts' }}/{{ $row['contract']->id }}">№ {{ $row['contract']->number }}</a></td>
                        <td>@if ($row['party_url'])<a href="{{ $row['party_url'] }}">{{ $row['party'] }}</a>@else {{ __('registry.none') }} @endif</td>
                        <td>@include('reports.properties', ['properties' => $row['properties']])</td>
                        <td>{{ Format::date($row['end_on']) }}</td>
                        <td class="num">{{ $row['days'] }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif
@endsection
