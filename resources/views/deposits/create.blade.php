@extends('layouts.app')

@use('App\Support\Format')
@use('App\Services\Charges\Cents')

@php($heading = __('deposits.title', ['number' => $contract->number]))
@section('title', $heading)
@section('width', 'wide')

@section('content')
    <h1>{{ $heading }}</h1>
    <p class="muted">{{ $contract->tenant?->name }} · {{ __('dossiers.owes_on_contract') }}: @include('dossiers.owes-amount', ['amount' => $owes])</p>
    <p class="muted">{{ __('deposits.intro') }}</p>

    @php($f = 'deposits.fields.')
    <form method="POST" action="/lease-contracts/{{ $contract->id }}/deposit-closings">
        @csrf
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>{{ __($f.'property') }}</th><th class="num">{{ __('deposits.held') }}</th>
                    @foreach (array_keys(\App\Services\Payments\DepositClosingService::METHODS) as $method)<th>{{ __('deposits.methods.'.$method) }}</th>@endforeach
                </tr>
                </thead>
                <tbody>
                @forelse ($properties as $property)
                    <tr>
                        <td>№ {{ $property->number }}</td>
                        <td class="num">{{ Format::money(Cents::format($held[$property->id] ?? 0)) }}</td>
                        @foreach (array_keys(\App\Services\Payments\DepositClosingService::METHODS) as $method)
                            <td><input type="text" inputmode="decimal" name="lines[{{ $property->id }}][{{ $method }}]" value="{{ old('lines.'.$property->id.'.'.$method) }}"
                                       aria-label="{{ __('deposits.methods.'.$method) }} № {{ $property->number }}" style="max-width: 140px;"></td>
                        @endforeach
                    </tr>
                @empty
                    <tr><td colspan="5" class="muted">{{ __('deposits.nothing_held') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <p class="muted">{{ __('deposits.hint') }}</p>

        <div class="grid">
            <div>
                <label for="closed_on">{{ __($f.'closed_on') }} *</label>
                <x-date-input id="closed_on" name="closed_on" required :value="old('closed_on', now()->format('d.m.Y'))" />
            </div>
            <div>
                <label for="refund_method">{{ __($f.'refund_method') }}</label>
                <select id="refund_method" name="refund_method">
                    @foreach (['bank', 'cash'] as $method)
                        <option value="{{ $method }}" @selected(old('refund_method', 'bank') === $method)>{{ __('payments.methods.'.$method) }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="bank_account_id">{{ __('payments.fields.bank_account_id') }}</label>
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
            <button type="submit">{{ __('deposits.save') }}</button>
            <a href="/lease-contracts/{{ $contract->id }}" style="margin-top: 18px;">{{ __('registry.cancel') }}</a>
        </div>
    </form>
@endsection
