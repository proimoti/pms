@extends('layouts.app')

@section('title', $beneficiary->name)
@section('width', 'wide')

@section('content')
    <h1>{{ __('registry.one.beneficiaries') }} № {{ $beneficiary->number }}: {{ $beneficiary->name }}</h1>

    <dl>
        <dt>{{ __('registry.fields.iban') }}</dt>
        <dd>
            @if (! auth()->user()->can_view_sensitive)
                {{ $beneficiary->getRawOriginal('iban') ? __('registry.iban_hidden') : __('registry.iban_missing') }}
            @else
                <code>{{ $beneficiary->iban ?? __('registry.iban_missing') }}</code>
            @endif
        </dd>
        <dt>{{ __('registry.fields.beneficiary_owner') }}</dt>
        <dd>
            @if ($beneficiary->owner)
                {{ __('registry.owner_himself') }}:
                <a href="/owners/{{ $beneficiary->owner->id }}">№ {{ $beneficiary->owner->number }} {{ $beneficiary->owner->name }}</a>
            @else
                {{ __('registry.none') }}
            @endif
        </dd>
    </dl>

    @include('dossiers.beneficiary')

    @include('registry.partials.record-actions', ['section' => 'beneficiaries', 'record' => $beneficiary])
@endsection
