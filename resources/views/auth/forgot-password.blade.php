@extends('layouts.app')

@section('title', __('login.forgot.title'))

@section('content')
    <h1>{{ __('login.forgot.title') }}</h1>
    <p class="muted">{{ __('login.forgot.intro') }}</p>

    <form method="POST" action="{{ route('password.email') }}">
        @csrf
        <label for="email">{{ __('login.email') }}</label>
        <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username">

        <div class="row">
            <button type="submit">{{ __('login.forgot.submit') }}</button>
            <a href="{{ route('login') }}">{{ __('login.forgot.back') }}</a>
        </div>
    </form>
@endsection
