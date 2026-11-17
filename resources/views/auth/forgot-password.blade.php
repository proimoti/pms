@extends('layouts.app')

@section('title', 'Забравена парола')

@section('content')
    <h1>Забравена парола</h1>
    <p class="muted">Въведете имейла си. Ако е в системата, ще получите писмо с връзка за нова парола.</p>

    <form method="POST" action="{{ route('password.email') }}">
        @csrf
        <label for="email">Имейл</label>
        <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username">

        <div class="row">
            <button type="submit">Изпрати връзка</button>
            <a href="{{ route('login') }}">Към входа</a>
        </div>
    </form>
@endsection
