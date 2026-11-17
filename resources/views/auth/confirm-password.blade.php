@extends('layouts.app')

@section('title', 'Потвърдете паролата')

@section('content')
    <h1>Потвърдете паролата</h1>
    <p class="muted">За тази стъпка въведете отново паролата си.</p>

    <form method="POST" action="{{ route('password.confirm.store') }}">
        @csrf
        <label for="password">Парола</label>
        <input id="password" type="password" name="password" required autofocus autocomplete="current-password">
        <button type="submit">Потвърди</button>
    </form>
@endsection
