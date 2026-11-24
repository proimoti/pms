@extends('layouts.app')

@use('App\Support\Format')
@use('App\Services\Charges\Month')

@php($heading = __('charges.statements.title').($month ? ' — '.Month::label($month) : ''))
@section('title', $heading)
@section('width', 'list')

@section('content')
    <div class="row">
        <h1 style="margin: 0;">{{ $heading }}</h1>
        @if ($month)
            <div class="actions">
                <a href="/statements?month={{ $previous }}">‹ {{ Month::label($month->subMonth()) }}</a>
                <a href="/statements?month={{ $next }}">{{ Month::label($month->addMonth()) }} ›</a>
                <a href="/charges?month={{ Month::key($month) }}">{{ __('charges.upcoming.back') }}</a>
            </div>
        @endif
    </div>

    {{-- Търсене (т. 2.11, 24.11.2026): номерата — точно съвпадение, наемателят — част от името. --}}
    @php($f = 'charges.statements.filters.')
    <form method="GET" action="/statements" class="row" style="margin: 18px 0 14px; justify-content: flex-start; align-items: flex-end;">
        <div>
            <label for="month">{{ __($f.'month') }}</label>
            <input id="month" type="text" name="month" value="{{ $filters['month'] }}" placeholder="{{ __($f.'month_hint') }}" style="min-width: 230px; max-width: 230px;">
        </div>
        <div>
            <label for="number">{{ __($f.'number') }}</label>
            <input id="number" type="text" inputmode="numeric" name="number" value="{{ $filters['number'] }}" style="max-width: 140px;">
        </div>
        <div>
            <label for="contract">{{ __($f.'contract') }}</label>
            <input id="contract" type="text" inputmode="numeric" name="contract" value="{{ $filters['contract'] }}" style="max-width: 170px;">
        </div>
        <div>
            <label for="tenant">{{ __($f.'tenant') }}</label>
            <input id="tenant" type="search" name="tenant" value="{{ $filters['tenant'] }}" style="max-width: 260px;">
        </div>
        <button type="submit" class="secondary" style="margin-top: 0;">{{ __($f.'search') }}</button>
        <a href="/statements?month=">{{ __($f.'clear') }}</a>
    </form>

    @if ($statements->isEmpty())
        <p class="muted">{{ $month && array_filter([$filters['number'], $filters['contract'], $filters['tenant']]) === [] ? __('charges.statements.none') : __('charges.statements.none_found') }}</p>
    @else
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>{{ __('charges.statements.number') }}</th>
                    @unless ($month)<th>{{ __('charges.statements.month') }}</th>@endunless
                    <th>{{ __('charges.fields.lease_contract') }}</th>
                    <th>{{ __('charges.fields.tenant') }}</th>
                    <th class="num">{{ __('charges.statements.total') }}</th>
                    <th>{{ __('charges.statements.issued_at') }}</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($statements as $statement)
                    <tr data-href="/statements/{{ $statement->id }}">
                        <td><a href="/statements/{{ $statement->id }}">№ {{ $statement->number }}</a></td>
                        @unless ($month)<td>{{ Month::label($statement->month) }}</td>@endunless
                        <td>@if ($statement->leaseContract)<a href="/lease-contracts/{{ $statement->lease_contract_id }}">№ {{ $statement->leaseContract->number }}</a>@endif</td>
                        <td>{{ $statement->tenant?->name }}</td>
                        <td class="num">{{ Format::money($statement->total) }}</td>
                        <td>{{ $statement->issued_at->format('d.m.Y') }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif
@endsection
