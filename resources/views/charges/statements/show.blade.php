@extends('layouts.app')

@use('App\Support\Format')
@use('App\Services\Charges\Month')

@php($heading = __('charges.statements.notice').' № '.$statement->number)
@section('title', $heading)
@section('width', 'wide')

@section('content')
    <h1>{{ $heading }}</h1>
    <p class="muted">{{ __('charges.statements.not_invoice') }}</p>

    <dl>
        <dt>{{ __('charges.fields.month') }}</dt><dd>{{ Month::label($statement->month) }}</dd>
        <dt>{{ __('charges.fields.tenant') }}</dt><dd>{{ $statement->tenant?->name }}</dd>
        <dt>{{ __('charges.fields.lease_contract') }}</dt>
        <dd><a href="/lease-contracts/{{ $statement->lease_contract_id }}">№ {{ $statement->leaseContract?->number }}</a></dd>
        <dt>{{ __('charges.statements.issued_at') }}</dt><dd>{{ $statement->issued_at->format('d.m.Y') }} ({{ $statement->issuedBy?->name }})</dd>
    </dl>

    <div class="table-wrap">
        <table>
            <thead>
            <tr>
                <th>{{ __('charges.fields.property') }}</th>
                <th>{{ __('charges.fields.type') }}</th>
                <th class="num">{{ __('charges.fields.days') }}</th>
                <th class="num">{{ __('charges.fields.amount') }}</th>
                <th>{{ __('charges.fields.note') }}</th>
            </tr>
            </thead>
            <tbody>
            @foreach ($statement->lines as $line)
                <tr @class(['zero' => (float) $line->amount == 0])>
                    <td>№ {{ $line->property?->number }} {{ \App\Support\Address::format($line->property) }}</td>
                    <td>{{ __('charges.types.'.$line->type->value) }}</td>
                    <td class="num">{{ $line->days }}</td>
                    <td class="num">{{ Format::money($line->amount) }}</td>
                    <td>{{ $line->note }}</td>
                </tr>
            @endforeach
            </tbody>
            <tfoot>
            <tr>
                <th colspan="3">{{ __('charges.statements.total') }}</th>
                <th class="num">{{ Format::money($statement->total) }}</th>
                <th></th>
            </tr>
            </tfoot>
        </table>
    </div>
@endsection
