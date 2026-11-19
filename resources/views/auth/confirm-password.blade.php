@extends('layouts.app')

@section('title', __('login.confirm_password.title'))

@section('content')
    <h1>{{ __('login.confirm_password.title') }}</h1>
    <p class="muted">{{ __('login.confirm_password.intro') }}</p>

    <form method="POST" action="{{ route('password.confirm.store') }}">
        @csrf
        <label for="password">{{ __('login.password') }}</label>
        <input id="password" type="password" name="password" required autofocus autocomplete="current-password">
        <button type="submit">{{ __('login.confirm') }}</button>
    </form>
@endsection
