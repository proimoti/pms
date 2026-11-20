@extends('layouts.app')

@section('title', __('charges.upcoming.edit_title'))
@section('width', 'wide')

@section('content')
    <h1>{{ __('charges.upcoming.edit_title') }}</h1>

    <form method="POST" action="{{ $upcoming ? '/upcoming-charges/'.$upcoming->id : '/upcoming-charges' }}">
        @csrf
        @if ($upcoming) @method('PUT') @endif

        @if ($upcoming)
            <dl>
                <dt>{{ __('charges.fields.lease_contract') }}</dt><dd>№ {{ $upcoming->leaseContract?->number }} — {{ $upcoming->leaseContract?->tenant?->name }}</dd>
                <dt>{{ __('charges.fields.property') }}</dt><dd>№ {{ $upcoming->property?->number }}</dd>
                <dt>{{ __('charges.fields.type') }}</dt><dd>{{ __('charges.types.'.$upcoming->type->value) }}</dd>
            </dl>
        @else
            <label for="lease_contract_id">{{ __('charges.fields.lease_contract') }} *</label>
            <select id="lease_contract_id" name="lease_contract_id">
                <option value=""></option>
                @foreach ($leases as $lease)
                    <option value="{{ $lease->id }}" @selected((string) old('lease_contract_id') === (string) $lease->id)>№ {{ $lease->number }} — {{ $lease->tenant?->name }}</option>
                @endforeach
            </select>

            <label for="property_id">{{ __('charges.fields.property') }} *</label>
            <select id="property_id" name="property_id">
                <option value=""></option>
                @foreach ($properties as $property)
                    <option value="{{ $property->id }}" @selected((string) old('property_id') === (string) $property->id)>№ {{ $property->number }} {{ \App\Support\Address::format($property) }}</option>
                @endforeach
            </select>

            <label for="type">{{ __('charges.fields.type') }} *</label>
            <select id="type" name="type">
                @foreach ($types as $type)
                    <option value="{{ $type->value }}" @selected(old('type') === $type->value)>{{ __('charges.types.'.$type->value) }}</option>
                @endforeach
            </select>
        @endif

        <label for="month">{{ __('charges.fields.month') }} *</label>
        <input id="month" type="text" inputmode="numeric" maxlength="7" name="month" placeholder="мм.гггг"
               value="{{ old('month', $upcoming?->month?->format('m.Y')) }}" style="max-width: 160px;">
        <div class="muted">{{ __('charges.hints.month') }}</div>

        <label for="amount">{{ __('charges.fields.amount') }} *</label>
        <input id="amount" type="text" inputmode="decimal" name="amount" value="{{ old('amount', $upcoming?->amount) }}" style="max-width: 200px;">

        <label for="note">{{ __('charges.fields.note') }}</label>
        <input id="note" type="text" name="note" value="{{ old('note', $upcoming?->note) }}">
        <div class="muted">{{ __('charges.hints.note_other') }}</div>

        <div class="actions">
            <button type="submit">{{ __('registry.save') }}</button>
            <a href="/upcoming-charges" style="margin-top: 18px;">{{ __('registry.cancel') }}</a>
        </div>
    </form>

    @if ($upcoming && ! $upcoming->cancelled_at)
        <h2>{{ __('charges.upcoming.cancel') }}</h2>
        <form method="POST" action="/upcoming-charges/{{ $upcoming->id }}/cancel">
            @csrf
            <label for="reason">{{ __('charges.upcoming.cancel_reason') }} *</label>
            <input id="reason" type="text" name="reason" maxlength="500">
            <div class="actions">
                <button type="submit" class="secondary">{{ __('charges.upcoming.cancel') }}</button>
            </div>
        </form>
    @endif
@endsection
