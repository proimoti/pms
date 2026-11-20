@extends('layouts.app')

@php($isNew = ! $property->exists)
{{-- В малкия прозорец „+ Нов“ от формите на договорите. --}}
@php($popup = $isNew && (request()->boolean('popup') || old('popup') === '1'))
@section('title', $isNew ? __('registry.new.properties') : __('registry.one.properties').' № '.$property->number)
@section('width', 'full')

@section('content')
    <h1>{{ $isNew ? __('registry.new.properties') : __('registry.one.properties').' № '.$property->number }}</h1>

    <form method="POST" action="{{ $isNew ? '/properties' : '/properties/'.$property->id }}">
        @csrf
        @unless ($isNew) @method('PUT') @endunless
        @if ($popup)<input type="hidden" name="popup" value="1">@endif

        <div class="grid">
            <div>
                <label for="number">{{ __('registry.fields.number') }}@unless ($isNew) *@endunless</label>
                <input id="number" type="number" min="1" name="number" value="{{ old('number', $property->number) }}" @unless ($isNew) required @endunless>
                @if ($isNew)<div class="muted">{{ __('registry.number_hint') }}</div>@endif
            </div>
            <div>
                <label for="property_type_id">{{ __('registry.fields.property_type_id') }} *</label>
                <select id="property_type_id" name="property_type_id" required>
                    <option value=""></option>
                    @foreach ($types as $type)
                        <option value="{{ $type->id }}" @selected((string) old('property_type_id', $property->property_type_id) === (string) $type->id)>{{ $type->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="area">{{ __('registry.fields.area') }}</label>
                <input id="area" type="text" inputmode="decimal" name="area" value="{{ old('area', $property->area) }}">
            </div>
            <div>
                <label for="broker_id">{{ __('registry.fields.broker_id') }}</label>
                <select id="broker_id" name="broker_id">
                    <option value="">{{ __('registry.none') }}</option>
                    @foreach ($brokers as $broker)
                        <option value="{{ $broker->id }}" @selected((string) old('broker_id', $property->broker_id) === (string) $broker->id)>{{ $broker->name }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        <h2>{{ __('registry.fields.address') }}</h2>
        @include('registry.partials.address-fields', ['model' => $property, 'cityRequired' => true])

        <label for="note">{{ __('registry.fields.note') }}</label>
        <textarea id="note" name="note">{{ old('note', $property->note) }}</textarea>

        <div class="actions">
            <button type="submit">{{ __('registry.save') }}</button>
            <a href="{{ $isNew ? '/properties' : '/properties/'.$property->id }}" style="margin-top: 18px;" @if ($popup) data-popup-cancel @endif>{{ __('registry.cancel') }}</a>
        </div>
    </form>
@endsection
