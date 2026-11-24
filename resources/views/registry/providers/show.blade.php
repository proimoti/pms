@extends('layouts.app')

@section('title', $provider->name)
@section('width', 'wide')

@section('content')
    <h1>{{ __('registry.one.providers') }}: {{ $provider->name }}</h1>

    <dl>
        <dt>{{ __('utilities.fields.kind') }}</dt><dd>{{ $provider->kind ? __('utilities.kinds.'.$provider->kind->value) : __('registry.none') }}</dd>
        <dt>{{ __('utilities.fields.easypay_name') }}</dt><dd>{{ $provider->easypay_name ?? __('registry.none') }}</dd>
        <dt>{{ __('utilities.fields.epay_name') }}</dt><dd>{{ $provider->epay_name ?? __('registry.none') }}</dd>
        <dt>{{ __('utilities.fields.note') }}</dt><dd style="white-space: pre-line;">{{ $provider->note ?? __('registry.none') }}</dd>
    </dl>

    @include('registry.partials.record-actions', ['section' => 'providers', 'record' => $provider])
@endsection
