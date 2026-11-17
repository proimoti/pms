@extends('layouts.app')

@section('title', 'Вход')

@section('content')
    <h1>Вход</h1>

    <form method="POST" action="{{ route('login') }}">
        @csrf
        <label for="email">Имейл</label>
        <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username">

        <label for="password">Парола</label>
        <input id="password" type="password" name="password" required autocomplete="current-password">

        <div class="row">
            <button type="submit">Влез</button>
            <a href="{{ route('password.request') }}">Забравена парола</a>
        </div>
    </form>
@endsection
