@extends('layouts.app')

@use('App\Support\Format')
@use('App\Services\Charges\Month')

@php($heading = ($row ? __('charges.screens.edit_title') : __('charges.screens.create_title')).' — '.Month::label($month))
@section('title', $heading)
@section('width', 'wide')

@section('content')
    <h1>{{ $heading }}</h1>

    @if ($row)
        <dl>
            <dt>{{ __('charges.fields.contract') }}</dt>
            <dd>
                @if ($row->leaseContract)
                    {{ __('registry.one.lease-contracts') }} № {{ $row->leaseContract->number }} — {{ $row->leaseContract->tenant?->name }}
                @else
                    {{ __('registry.one.owner-contracts') }} № {{ $row->ownerContract?->number }} — {{ $row->ownerContract?->owner?->name }}
                @endif
            </dd>
            <dt>{{ __('charges.fields.property') }}</dt><dd>№ {{ $row->property?->number }} {{ \App\Support\Address::format($row->property) }}</dd>
            <dt>{{ __('charges.fields.type') }}</dt><dd>{{ __('charges.types.'.$row->type->value) }}</dd>
            <dt>{{ __('charges.fields.source') }}</dt><dd>{{ __('charges.sources.'.$row->source->value) }}</dd>
            @if ($row->proposed_amount !== null)
                <dt>{{ __('charges.screens.proposed_now', ['amount' => '']) }}</dt><dd>{{ Format::money($row->proposed_amount) }} €</dd>
            @endif
            @if ($row->computed_amount !== null)
                <dt></dt><dd class="muted">{{ __('charges.screens.computed_hint', ['amount' => Format::money($row->computed_amount)]) }}</dd>
            @endif
            @if ($row->removed_at)
                <dt>{{ __('charges.screens.removed') }}</dt><dd>{{ $row->remove_reason }} ({{ $row->removedBy?->name }}, {{ $row->removed_at->format('d.m.Y H:i') }})</dd>
            @endif
        </dl>
    @endif

    @unless ($row?->removed_at)
        <form method="POST" action="{{ $row ? '/charges/'.$row->id : '/charges' }}">
            @csrf
            @if ($row) @method('PUT') @else <input type="hidden" name="month" value="{{ Month::key($month) }}"> @endif

            @unless ($row)
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
                        <option value="{{ $management->id }}" @selected((string) old('owner_contract_id') === (string) $management->id)>№ {{ $management->number }} — {{ $management->owner?->name ?? __('contracts.types.own') }}</option>
                    @endforeach
                </select>

                <label for="property_id">{{ __('charges.fields.property') }} *</label>
                <select id="property_id" name="property_id">
                    <option value=""></option>
                    @foreach ($properties as $property)
                        <option value="{{ $property->id }}" @selected((string) old('property_id') === (string) $property->id)>№ {{ $property->number }} {{ \App\Support\Address::format($property) }}</option>
                    @endforeach
                </select>
            @endunless

            <label for="amount">{{ __('charges.fields.amount') }} *</label>
            <input id="amount" type="text" inputmode="decimal" name="amount" value="{{ old('amount', $row?->amount) }}" style="max-width: 200px;">

            <label for="note">{{ __('charges.fields.note') }}</label>
            <input id="note" type="text" name="note" value="{{ old('note', $row?->note) }}">
            <div class="muted">{{ __('charges.hints.note_other') }}</div>

            <label for="reason">{{ __('charges.fields.reason') }} *</label>
            <input id="reason" type="text" name="reason" value="{{ old('reason') }}" maxlength="500">
            <div class="muted">{{ __('charges.hints.reason') }}</div>

            <div class="actions">
                <button type="submit">{{ __('registry.save') }}</button>
                <a href="/charges?month={{ Month::key($month) }}" style="margin-top: 18px;">{{ __('registry.cancel') }}</a>
            </div>
        </form>
    @endunless

    @if ($row)
        <h2>{{ $row->removed_at ? __('charges.screens.restore') : __('charges.screens.remove') }}</h2>
        <form method="POST" action="/charges/{{ $row->id }}/{{ $row->removed_at ? 'restore' : 'remove' }}">
            @csrf
            <label for="remove_reason">{{ __('charges.fields.reason') }} *</label>
            <input id="remove_reason" type="text" name="reason" maxlength="500">
            <div class="actions">
                <button type="submit" class="secondary">{{ $row->removed_at ? __('charges.screens.restore') : __('charges.screens.remove') }}</button>
            </div>
        </form>
    @endif
@endsection
