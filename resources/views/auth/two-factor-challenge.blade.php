@extends('layouts.app')

@section('title', 'Код за вход')

@section('content')
    <h1>Код за вход</h1>
    <p class="muted">Въведете 6-цифрения код от приложението на телефона си.</p>

    <form method="POST" action="{{ route('two-factor.login.store') }}">
        @csrf
        <label for="code">Код</label>
        <input id="code" type="text" name="code" inputmode="numeric" autocomplete="one-time-code" autofocus>
        <button type="submit">Влез</button>
    </form>

    <details style="margin-top: 24px;">
        <summary>Нямам достъп до телефона</summary>
        <form method="POST" action="{{ route('two-factor.login.store') }}">
            @csrf
            <label for="recovery_code">Резервен код</label>
            <input id="recovery_code" type="text" name="recovery_code" autocomplete="one-time-code">
            <p class="muted">Всеки резервен код важи само веднъж. Ако нямате и резервни кодове, свържете се с доставчика на услугата.</p>
            <button type="submit" class="secondary">Влез с резервен код</button>
        </form>
    </details>
@endsection
