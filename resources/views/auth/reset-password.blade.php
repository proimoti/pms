@extends('layouts.app')

@section('title', __('login.reset.title'))

@section('content')
    <h1>{{ __('login.reset.title') }}</h1>
    <p class="muted">{{ __('login.reset.intro') }}</p>

    <form method="POST" action="{{ route('password.update') }}">
        @csrf
        <input type="hidden" name="token" value="{{ $request->route('token') }}">

        <label for="email">{{ __('login.email') }}</label>
        <input id="email" type="email" name="email" value="{{ old('email', $request->email) }}" required autocomplete="username">

        <label for="password">{{ __('login.reset.password') }}</label>
        <input id="password" type="password" name="password" required minlength="12" autocomplete="new-password">

        <label for="password_confirmation">{{ __('login.reset.password_confirmation') }}</label>
        <input id="password_confirmation" type="password" name="password_confirmation" required minlength="12" autocomplete="new-password">

        <button type="submit">{{ __('login.reset.submit') }}</button>
    </form>
@endsection
