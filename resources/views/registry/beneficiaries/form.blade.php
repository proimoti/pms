@extends('layouts.app')

@php($isNew = ! $beneficiary->exists)
@php($heading = $isNew ? __('registry.new.beneficiaries') : __('registry.one.beneficiaries'))
@section('title', $heading)
@section('width', 'wide')

@section('content')
    <h1>{{ $heading }}</h1>

    <form method="POST" action="{{ $isNew ? '/beneficiaries' : '/beneficiaries/'.$beneficiary->id }}">
        @csrf
        @unless ($isNew) @method('PUT') @endunless

        <label for="name">{{ __('registry.fields.name') }} *</label>
        <input id="name" type="text" name="name" value="{{ old('name', $beneficiary->name) }}" required>

        {{-- IBAN само с правото за ЕГН и IBAN. --}}
        @if (auth()->user()->can_view_sensitive)
            <label for="iban">{{ __('registry.fields.iban') }}</label>
            <input id="iban" type="text" name="iban" value="{{ $beneficiary->iban }}" autocomplete="off">
            <div class="muted">{{ __('registry.iban_hint') }}</div>
        @else
            <p class="muted">{{ __('registry.sensitive_only') }}</p>
        @endif

        <div class="actions">
            <button type="submit">{{ __('registry.save') }}</button>
            <a href="{{ $isNew ? '/beneficiaries' : '/beneficiaries/'.$beneficiary->id }}" style="margin-top: 18px;">{{ __('registry.cancel') }}</a>
        </div>
    </form>
@endsection
