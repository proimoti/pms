@extends('layouts.app')

@php($heading = __('registry.one.'.$section).' № '.$party->number)
@section('title', $heading)
@section('width', 'full')

@section('content')
    <h1>{{ $heading }}: {{ $party->name }}</h1>

    <dl>
        <dt>{{ __('registry.fields.kind') }}</dt><dd>{{ __('registry.kinds.'.$party->kind->value) }}</dd>
        @if ($party->isLegalEntity())
            <dt>{{ __('registry.fields.eik') }}</dt><dd>{{ $party->eik ?? __('registry.none') }}</dd>
            <dt>{{ __('registry.fields.manager_name') }}</dt><dd>{{ $party->manager_name ?? __('registry.none') }}</dd>
        @endif
        <dt>{{ __('registry.fields.address') }}</dt><dd>{{ \App\Support\Address::format($party) ?: __('registry.none') }}@if ($party->country !== 'BG') ({{ $party->country }})@endif</dd>
        <dt>{{ __('registry.fields.phone') }}</dt><dd>{{ $party->phone ?? __('registry.none') }}</dd>
        <dt>{{ __('registry.fields.email') }}</dt><dd>{{ $party->email ?? __('registry.none') }}</dd>
        <dt>{{ __('registry.fields.note') }}</dt><dd style="white-space: pre-line;">{{ $party->note ?? __('registry.none') }}</dd>
    </dl>

    @include('registry.partials.record-actions', ['section' => $section, 'record' => $party])
@endsection
