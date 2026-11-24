@extends('layouts.app')

@php($isNew = ! $broker->exists)
@php($heading = $isNew ? __('registry.new.brokers') : __('registry.one.brokers').' № '.$broker->number)
@section('title', $heading)
@section('width', 'wide')

@section('content')
    <h1>{{ $heading }}</h1>

    <form method="POST" action="{{ $isNew ? '/brokers' : '/brokers/'.$broker->id }}">
        @csrf
        @unless ($isNew) @method('PUT') @endunless

        <label for="name">{{ __('registry.fields.name') }} *</label>
        <input id="name" type="text" name="name" value="{{ old('name', $broker->name) }}" required>

        <label for="phone">{{ __('registry.fields.phone') }}</label>
        <input id="phone" type="text" name="phone" value="{{ old('phone', $broker->phone) }}">

        <label for="email">{{ __('registry.fields.email') }}</label>
        <input id="email" type="email" name="email" value="{{ old('email', $broker->email) }}">

        <label for="note">{{ __('registry.fields.note') }}</label>
        <textarea id="note" name="note">{{ old('note', $broker->note) }}</textarea>

        <div class="actions">
            <button type="submit">{{ __('registry.save') }}</button>
            <a href="{{ $isNew ? '/brokers' : '/brokers/'.$broker->id }}" style="margin-top: 18px;">{{ __('registry.cancel') }}</a>
        </div>
    </form>
@endsection
