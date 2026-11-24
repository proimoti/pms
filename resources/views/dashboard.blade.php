@extends('layouts.app')

@section('title', 'Начало')
@section('width', 'wide')

@php($user = auth()->user())

@section('content')
    <h1>Начало</h1>
    <p>Влезли сте като <strong>{{ $user->name }}</strong>.</p>
    <p>Фирма: <strong>{{ $user->company?->name ?? 'всички фирми (администратор на приложението)' }}</strong></p>

    <form method="POST" action="{{ route('logout') }}">
        @csrf
        <button type="submit" class="secondary">{{ __('registry.logout') }}</button>
    </form>
@endsection
