@extends('layouts.app')

@section('title', 'Нова парола')

@section('content')
    <h1>Нова парола</h1>
    <p class="muted">Паролата трябва да е поне 12 знака.</p>

    <form method="POST" action="{{ route('password.update') }}">
        @csrf
        <input type="hidden" name="token" value="{{ $request->route('token') }}">

        <label for="email">Имейл</label>
        <input id="email" type="email" name="email" value="{{ old('email', $request->email) }}" required autocomplete="username">

        <label for="password">Нова парола</label>
        <input id="password" type="password" name="password" required minlength="12" autocomplete="new-password">

        <label for="password_confirmation">Повторете новата парола</label>
        <input id="password_confirmation" type="password" name="password_confirmation" required minlength="12" autocomplete="new-password">

        <button type="submit">Смени паролата</button>
    </form>
@endsection
