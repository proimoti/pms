@extends('layouts.app')

@section('title', __('login.challenge.title'))

@section('content')
    <h1>{{ __('login.challenge.title') }}</h1>
    <p class="muted">{{ __('login.challenge.intro') }}</p>

    <form method="POST" action="{{ route('two-factor.login.store') }}">
        @csrf
        <label for="code">{{ __('login.code') }}</label>
        <input id="code" type="text" name="code" inputmode="numeric" autocomplete="one-time-code" autofocus>
        <button type="submit">{{ __('login.login.submit') }}</button>
    </form>

    <details style="margin-top: 24px;">
        <summary>{{ __('login.challenge.no_phone') }}</summary>
        <form method="POST" action="{{ route('two-factor.login.store') }}">
            @csrf
            <label for="recovery_code">{{ __('login.challenge.recovery_code') }}</label>
            <input id="recovery_code" type="text" name="recovery_code" autocomplete="one-time-code">
            <p class="muted">{{ __('login.challenge.recovery_note') }}</p>
            <button type="submit" class="secondary">{{ __('login.challenge.recovery_submit') }}</button>
        </form>
    </details>
@endsection
