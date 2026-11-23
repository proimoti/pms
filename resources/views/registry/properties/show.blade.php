@extends('layouts.app')

@section('title', __('registry.one.properties').' № '.$property->number)
@section('width', 'full')

@section('content')
    <h1>{{ __('registry.one.properties') }} № {{ $property->number }}</h1>

    <dl>
        <dt>{{ __('registry.fields.property_type_id') }}</dt><dd>{{ $property->propertyType?->name }}</dd>
        <dt>{{ __('registry.fields.address') }}</dt><dd>{{ \App\Support\Address::format($property) }}</dd>
        <dt>{{ __('registry.fields.area') }}</dt><dd>{{ $property->area !== null ? number_format((float) $property->area, 2, ',', ' ') : __('registry.none') }}</dd>
        <dt>{{ __('registry.fields.broker_id') }}</dt><dd>{{ $property->broker?->name ?? __('registry.none') }}</dd>
        <dt>{{ __('registry.fields.note') }}</dt><dd style="white-space: pre-line;">{{ $property->note ?? __('registry.none') }}</dd>
    </dl>

    @include('dossiers.property')

    @include('registry.partials.record-actions', ['section' => 'properties', 'record' => $property])
@endsection
