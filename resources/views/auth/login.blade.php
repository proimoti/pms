@extends('layouts.app')

@section('title', __('login.login.title'))

@section('content')
    <h1>{{ __('login.login.title') }}</h1>

    <form method="POST" action="{{ route('login') }}">
        @csrf
        <label for="email">{{ __('login.email') }}</label>
        <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username">

        <label for="password">{{ __('login.password') }}</label>
        <input id="password" type="password" name="password" required autocomplete="current-password">

        <div class="row">
            <button type="submit">{{ __('login.login.submit') }}</button>
            <a href="{{ route('password.request') }}">{{ __('login.login.forgot') }}</a>
        </div>
    </form>
@endsection
