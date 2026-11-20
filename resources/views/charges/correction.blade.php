@extends('layouts.app')

@use('App\Services\Charges\Month')

@php($heading = __('charges.issue.manual_title').' — '.Month::label($month))
@section('title', $heading)
@section('width', 'wide')

@section('content')
    <h1>{{ $heading }}</h1>
    <p class="muted">{{ __('charges.issue.manual_hint') }}</p>

    <form method="POST" action="/charges/corrections/manual">
        @csrf
        <input type="hidden" name="month" value="{{ Month::key($month) }}">

        <label for="type">{{ __('charges.fields.type') }} *</label>
        <select id="type" name="type">
            @foreach ($types as $type)
                <option value="{{ $type->value }}" @selected(old('type') === $type->value)>{{ __('charges.types.'.$type->value) }}</option>
            @endforeach
        </select>
        <div class="muted">{{ __('charges.hints.manual_owner') }}</div>

        <label for="lease_contract_id">{{ __('charges.fields.lease_contract') }}</label>
        <select id="lease_contract_id" name="lease_contract_id">
            <option value=""></option>
            @foreach ($leases as $lease)
                <option value="{{ $lease->id }}" @selected((string) old('lease_contract_id') === (string) $lease->id)>№ {{ $lease->number }} — {{ $lease->tenant?->name }}</option>
            @endforeach
        </select>

        <label for="owner_contract_id">{{ __('charges.fields.owner_contract') }}</label>
        <select id="owner_contract_id" name="owner_contract_id">
            <option value=""></option>
            @foreach ($managements as $management)
                <option value="{{ $management->id }}" @selected((string) old('owner_contract_id') === (string) $management->id)>№ {{ $management->number }} — {{ $management->owner?->name }}</option>
            @endforeach
        </select>

        <label for="property_id">{{ __('charges.fields.property') }} *</label>
        <select id="property_id" name="property_id">
            <option value=""></option>
            @foreach ($properties as $property)
                <option value="{{ $property->id }}" @selected((string) old('property_id') === (string) $property->id)>№ {{ $property->number }} {{ \App\Support\Address::format($property) }}</option>
            @endforeach
        </select>

        <label for="amount">{{ __('charges.fields.amount') }} *</label>
        <input id="amount" type="text" inputmode="decimal" name="amount" value="{{ old('amount') }}" style="max-width: 200px;">

        <label for="reason">{{ __('charges.fields.reason') }} *</label>
        <input id="reason" type="text" name="reason" value="{{ old('reason') }}" maxlength="500">
        <div class="muted">{{ __('charges.hints.reason') }}</div>

        <div class="actions">
            <button type="submit">{{ __('registry.save') }}</button>
            <a href="/charges?month={{ Month::key($month) }}" style="margin-top: 18px;">{{ __('registry.cancel') }}</a>
        </div>
    </form>
@endsection
