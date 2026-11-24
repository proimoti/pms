@extends('layouts.app')

@php($isNew = ! $provider->exists)
@php($heading = $isNew ? __('registry.new.providers') : __('registry.one.providers'))
@section('title', $heading)
@section('width', 'wide')

@section('content')
    <h1>{{ $heading }}</h1>

    <form method="POST" action="{{ $isNew ? '/providers' : '/providers/'.$provider->id }}">
        @csrf
        @unless ($isNew) @method('PUT') @endunless

        <label for="name">{{ __('utilities.fields.name') }} *</label>
        <input id="name" type="text" name="name" value="{{ old('name', $provider->name) }}" required>

        <label for="kind">{{ __('utilities.fields.kind') }}</label>
        <select id="kind" name="kind">
            <option value=""></option>
            @foreach (\App\Enums\UtilityKind::cases() as $kind)
                <option value="{{ $kind->value }}" @selected(old('kind', $provider->kind?->value) === $kind->value)>{{ __('utilities.kinds.'.$kind->value) }}</option>
            @endforeach
        </select>
        <div class="muted">{{ __('utilities.hints.provider_kind') }}</div>

        <p class="muted">{{ __('utilities.hints.provider_names') }}</p>

        <label for="easypay_name">{{ __('utilities.fields.easypay_name') }}</label>
        <input id="easypay_name" type="text" name="easypay_name" value="{{ old('easypay_name', $provider->easypay_name) }}">

        <label for="epay_name">{{ __('utilities.fields.epay_name') }}</label>
        <input id="epay_name" type="text" name="epay_name" value="{{ old('epay_name', $provider->epay_name) }}">

        <label for="note">{{ __('utilities.fields.note') }}</label>
        <textarea id="note" name="note">{{ old('note', $provider->note) }}</textarea>

        <div class="actions">
            <button type="submit">{{ __('registry.save') }}</button>
            <a href="{{ $isNew ? '/providers' : '/providers/'.$provider->id }}" style="margin-top: 18px;">{{ __('registry.cancel') }}</a>
        </div>
    </form>
@endsection
