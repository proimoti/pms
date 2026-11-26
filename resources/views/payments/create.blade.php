@extends('layouts.app')

@use('App\Support\Format')

@php($heading = __('payments.title', ['number' => $contract->number]))
@section('title', $heading)
@section('width', 'wide')

@section('content')
    <h1>{{ $heading }}</h1>
    <p class="muted">{{ $contract->tenant?->name }} · {{ __('dossiers.owes_on_contract') }}: @include('dossiers.owes-amount', ['amount' => $owes])</p>
    <p class="muted">{{ __('payments.intro') }}</p>

    @php($f = 'payments.fields.')
    <form method="POST" action="/lease-contracts/{{ $contract->id }}/payments">
        @csrf
        <div class="grid">
            <div>
                <label for="amount">{{ __($f.'amount') }} *</label>
                <input id="amount" type="text" inputmode="decimal" name="amount" value="{{ old('amount') }}" required>
            </div>
            <div>
                <label for="paid_on">{{ __($f.'paid_on') }} *</label>
                <x-date-input id="paid_on" name="paid_on" required :value="old('paid_on', now()->format('d.m.Y'))" />
            </div>
            <div>
                <label for="method">{{ __($f.'method') }} *</label>
                <select id="method" name="method" required>
                    @foreach (['bank', 'cash'] as $method)
                        <option value="{{ $method }}" @selected(old('method', 'bank') === $method)>{{ __('payments.methods.'.$method) }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="bank_account_id">{{ __($f.'bank_account_id') }}</label>
                <select id="bank_account_id" name="bank_account_id">
                    @foreach ($accounts as $account)
                        <option value="{{ $account->id }}" @selected((string) old('bank_account_id') === (string) $account->id)>{{ $account->formattedIban() }}@if ($account->bank_name) · {{ $account->bank_name }}@endif</option>
                    @endforeach
                </select>
            </div>
        </div>
        <label for="reference">{{ __($f.'reference') }}</label>
        <input id="reference" type="text" name="reference" maxlength="255" value="{{ old('reference') }}">
        <label for="note">{{ __($f.'note') }}</label>
        <textarea id="note" name="note">{{ old('note') }}</textarea>

        <div class="actions">
            <button type="submit">{{ __('registry.save') }}</button>
            <a href="/lease-contracts/{{ $contract->id }}" style="margin-top: 18px;">{{ __('registry.cancel') }}</a>
        </div>
    </form>
@endsection
