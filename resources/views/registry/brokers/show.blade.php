@extends('layouts.app')

@section('title', $broker->name)
@section('width', 'wide')

@section('content')
    <h1>{{ __('registry.one.brokers') }}: {{ $broker->name }}</h1>

    <dl>
        <dt>{{ __('registry.fields.phone') }}</dt><dd>{{ $broker->phone ?? __('registry.none') }}</dd>
        <dt>{{ __('registry.fields.email') }}</dt><dd>{{ $broker->email ?? __('registry.none') }}</dd>
        <dt>{{ __('registry.fields.note') }}</dt><dd style="white-space: pre-line;">{{ $broker->note ?? __('registry.none') }}</dd>
    </dl>

    @include('registry.partials.record-actions', ['section' => 'brokers', 'record' => $broker])
@endsection
