@extends('layouts.app')

@php($heading = __('payouts.title', ['number' => $contract->number]))
@section('title', $heading)
@section('width', 'wide')

@section('content')
    <h1>{{ $heading }}</h1>
    <p class="muted">
        {{ $contract->owner?->name ?? __('contracts.screens.own_company') }}
        · {{ __('payouts.summary.left') }}: @include('dossiers.owes-amount', ['amount' => $left])
        @if ($beneficiary) · {{ __('payouts.payee') }}: {{ $beneficiary->name }}@endif
    </p>
    <p class="muted">{{ __('payouts.intro') }}</p>

    @php($f = 'payments.fields.')
    <form method="POST" action="/owner-contracts/{{ $contract->id }}/payouts">
        @csrf
        <div class="grid">
            <div>
                <label for="amount">{{ __($f.'amount') }} *</label>
                <input id="amount" type="text" inputmode="decimal" name="amount" value="{{ old('amount', $amount) }}" required>
            </div>
            <div>
                <label for="paid_on">{{ __($f.'paid_on') }} *</label>
                <x-date-input id="paid_on" name="paid_on" required :value="old('paid_on', now()->format('d.m.Y'))" />
            </div>
            <div>
                <label for="method">{{ __($f.'method') }} *</label>
                <select id="method" name="method" required>
                    @foreach (['bank', 'cash'] as $option)
                        <option value="{{ $option }}" @selected(old('method', $method) === $option)>{{ __('payments.methods.'.$option) }}</option>
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
        <label for="note">{{ __($f.'note') }}</label>
        <textarea id="note" name="note">{{ old('note') }}</textarea>

        <div class="actions">
            <button type="submit">{{ __('registry.save') }}</button>
            <a href="/owner-contracts/{{ $contract->id }}" style="margin-top: 18px;">{{ __('registry.cancel') }}</a>
        </div>
    </form>
@endsection
