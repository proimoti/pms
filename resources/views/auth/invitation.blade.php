@extends('layouts.app')

{{-- „Задай парола“ по покана (27.11.2026): с името на фирмата, без името на приложението. --}}
@section('title', __('invitation.title'))
@section('brand', $companyName)

@section('content')
    <p class="muted">{{ $companyName }}</p>
    <h1>{{ __('invitation.title') }}</h1>

    @if ($expired)
        <p>{{ __('invitation.expired') }}</p>
    @else
        <p class="muted">{{ __('invitation.intro') }}</p>

        <form method="POST" action="{{ url()->current() }}">
            @csrf
            <label for="email">{{ __('login.email') }}</label>
            <input id="email" type="email" value="{{ $email }}" readonly autocomplete="username">

            <label for="password">{{ __('login.reset.password') }}</label>
            <input id="password" type="password" name="password" required minlength="12" autocomplete="new-password">

            <label for="password_confirmation">{{ __('login.reset.password_confirmation') }}</label>
            <input id="password_confirmation" type="password" name="password_confirmation" required minlength="12" autocomplete="new-password">

            <button type="submit">{{ __('invitation.submit') }}</button>
        </form>
    @endif
@endsection
