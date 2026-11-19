@extends('layouts.app')

@section('title', __('login.setup.title'))
@section('width', 'wide')

@php($user = auth()->user())

@section('content')
    <h1>{{ __('login.setup.title') }}</h1>

    @if (! $user->two_factor_secret)
        {{-- 1. Още не е започната настройката. --}}
        <p>{{ __('login.setup.intro') }}</p>
        <form method="POST" action="{{ route('two-factor.enable') }}">
            @csrf
            <button type="submit">{{ __('login.setup.start') }}</button>
        </form>

    @elseif (! $user->two_factor_confirmed_at)
        {{-- 2. Ключът е създаден, чака код за потвърждение. --}}
        <p>{{ __('login.setup.scan') }}</p>
        <div>{!! $user->twoFactorQrCodeSvg() !!}</div>
        <p class="muted">{{ __('login.setup.manual') }}
            <code>{{ decrypt($user->two_factor_secret) }}</code></p>

        <form method="POST" action="{{ route('two-factor.confirm') }}">
            @csrf
            <label for="code">{{ __('login.setup.enter_code') }}</label>
            <input id="code" type="text" name="code" inputmode="numeric" autocomplete="one-time-code" autofocus>
            <button type="submit">{{ __('login.confirm') }}</button>
        </form>

    @else
        {{-- 3. Готово. --}}
        <p>{{ __('login.setup.enabled') }}</p>
        @if (session('status') === 'two-factor-authentication-confirmed')
            <p><strong>{{ __('login.setup.save_codes') }}</strong> {{ __('login.setup.codes_note') }}</p>
            <ul class="codes">
                @foreach ($user->recoveryCodes() as $code)
                    <li>{{ $code }}</li>
                @endforeach
            </ul>
        @endif
        <p><a href="{{ route('dashboard') }}">{{ __('login.setup.continue') }}</a></p>
    @endif

    <form method="POST" action="{{ route('logout') }}">
        @csrf
        <button type="submit" class="secondary">{{ __('login.logout') }}</button>
    </form>
@endsection
