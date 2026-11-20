@extends('layouts.app')

@use('App\Support\Format')
@use('App\Services\Charges\Month')

@php($heading = __('charges.statements.title').' — '.Month::label($month))
@section('title', $heading)
@section('width', 'full')

@section('content')
    <div class="row">
        <h1 style="margin: 0;">{{ $heading }}</h1>
        <div class="actions">
            <a href="/statements?month={{ $previous }}">‹ {{ Month::label($month->subMonth()) }}</a>
            <a href="/statements?month={{ $next }}">{{ Month::label($month->addMonth()) }} ›</a>
            <a href="/charges?month={{ Month::key($month) }}">{{ __('charges.upcoming.back') }}</a>
        </div>
    </div>

    @if ($statements->isEmpty())
        <p class="muted">{{ __('charges.statements.none') }}</p>
    @else
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>{{ __('charges.statements.number') }}</th>
                    <th>{{ __('charges.fields.lease_contract') }}</th>
                    <th>{{ __('charges.fields.tenant') }}</th>
                    <th class="num">{{ __('charges.statements.total') }}</th>
                    <th>{{ __('charges.statements.issued_at') }}</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($statements as $statement)
                    <tr>
                        <td><a href="/statements/{{ $statement->id }}">№ {{ $statement->number }}</a></td>
                        <td>№ {{ $statement->leaseContract?->number }}</td>
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
