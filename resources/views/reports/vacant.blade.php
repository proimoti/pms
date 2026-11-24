@extends('layouts.app')

@use('App\Support\Format')

@section('title', __('reports.vacant.title'))
@section('width', 'list')

@section('content')
    <h1>{{ __('reports.vacant.title') }}</h1>
    <p class="muted">{{ __('reports.vacant.intro') }}</p>

    <form method="GET" action="/reports/vacant" class="row" style="justify-content: flex-start; align-items: center;">
        <label style="margin: 0; font-weight: normal;"><input type="checkbox" name="managed" value="1" @checked($managed)> {{ __('reports.vacant.managed') }}</label>
        <button type="submit" class="secondary" style="margin-top: 0;">{{ __('reports.show') }}</button>
        <a class="button" style="margin-top: 0;" href="/reports/vacant?{{ http_build_query(array_filter(['managed' => $managed ? 1 : null, 'export' => 'xlsx'])) }}">{{ __('reports.download') }}</a>
    </form>

    @if ($rows === [])
        <p class="muted">{{ __('reports.empty') }}</p>
    @else
        @php($c = 'reports.vacant.columns.')
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>{{ __($c.'property') }}</th><th>{{ __($c.'address') }}</th><th>{{ __($c.'type') }}</th>
                    <th>{{ __($c.'since') }}</th><th>{{ __($c.'last_tenant') }}</th><th>{{ __($c.'managed') }}</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($rows as $row)
                    <tr>
                        <td><a href="/properties/{{ $row['property']->id }}">№ {{ $row['property']->number }}</a></td>
                        <td>{{ \App\Support\Address::format($row['property']) }}</td>
                        <td>{{ $row['property']->propertyType?->name }}</td>
                        <td>{{ $row['since'] ? Format::date($row['since']) : __('reports.vacant.never') }}</td>
                        <td>{{ $row['last_tenant'] ?? __('registry.none') }}</td>
                        <td>{{ $row['managed'] ? __('reports.vacant.yes') : __('reports.vacant.no') }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif
@endsection
