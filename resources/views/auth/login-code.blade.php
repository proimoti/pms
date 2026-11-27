@extends('layouts.app')

@section('title', __('portal.code.title'))

@section('content')
    <h1>{{ __('portal.code.title') }}</h1>
    <p class="muted">{{ __('portal.code.intro', ['minutes' => \App\Support\LoginCode::MINUTES]) }}</p>

    <form method="POST" action="/login/code">
        @csrf
        <label for="code">{{ __('login.code') }}</label>
        <input id="code" type="text" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" required autofocus>
        <button type="submit">{{ __('portal.code.submit') }}</button>
    </form>
    <form method="POST" action="/login/code/resend" style="margin-top: 12px;">
        @csrf
        <button type="submit" class="secondary">{{ __('portal.code.resend') }}</button>
    </form>
@endsection
