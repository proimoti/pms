@extends('layouts.app')

@section('title', 'Втори фактор')
@section('width', 'wide')

@php($user = auth()->user())

@section('content')
    <h1>Втори фактор</h1>

    @if (! $user->two_factor_secret)
        {{-- 1. Още не е започната настройката. --}}
        <p>За да влизате, освен паролата е нужен и код от приложение на телефона ви
            (например Google Authenticator или Microsoft Authenticator).</p>
        <form method="POST" action="{{ route('two-factor.enable') }}">
            @csrf
            <button type="submit">Започни настройката</button>
        </form>

    @elseif (! $user->two_factor_confirmed_at)
        {{-- 2. Ключът е създаден, чака код за потвърждение. --}}
        <p>1. Сканирайте този код с приложението на телефона си:</p>
        <div>{!! $user->twoFactorQrCodeSvg() !!}</div>
        <p class="muted">Ако не можете да сканирате, въведете ръчно ключа:
            <code>{{ decrypt($user->two_factor_secret) }}</code></p>

        <form method="POST" action="{{ route('two-factor.confirm') }}">
            @csrf
            <label for="code">2. Въведете 6-цифрения код от приложението</label>
            <input id="code" type="text" name="code" inputmode="numeric" autocomplete="one-time-code" autofocus>
            <button type="submit">Потвърди</button>
        </form>

    @else
        {{-- 3. Готово. --}}
        <p>Вторият фактор е включен.</p>
        @if (session('status') === 'two-factor-authentication-confirmed')
            <p><strong>Запишете резервните кодове</strong> и ги пазете на сигурно място.
                Ползват се, ако нямате достъп до телефона. Всеки код важи само веднъж.
                Показват се само сега.</p>
            <ul class="codes">
                @foreach ($user->recoveryCodes() as $code)
                    <li>{{ $code }}</li>
                @endforeach
            </ul>
        @endif
        <p><a href="{{ route('dashboard') }}">Продължи</a></p>
    @endif

    <form method="POST" action="{{ route('logout') }}">
        @csrf
        <button type="submit" class="secondary">Изход</button>
    </form>
@endsection
