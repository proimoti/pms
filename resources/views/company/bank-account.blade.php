@extends('layouts.app')

@php($isNew = ! $account->exists)
@section('title', $isNew ? __('company.bank.new') : __('company.bank.edit'))
@section('width', 'wide')

@section('content')
    <h1>{{ $isNew ? __('company.bank.new') : __('company.bank.edit') }}</h1>

    @php($b = 'company.bank.')
    <form method="POST" action="{{ $isNew ? '/company/bank-accounts' : '/company/bank-accounts/'.$account->id }}">
        @csrf
        @unless ($isNew) @method('PUT') @endunless
        <label for="bank_name">{{ __($b.'bank_name') }}</label>
        <input id="bank_name" type="text" name="bank_name" value="{{ old('bank_name', $account->bank_name) }}">

        <label for="iban">{{ __($b.'iban') }} *</label>
        <input id="iban" type="text" name="iban" value="{{ old('iban', $account->exists ? $account->formattedIban() : '') }}" required>

        <label for="bic">{{ __($b.'bic') }}</label>
        <input id="bic" type="text" name="bic" value="{{ old('bic', $account->bic) }}" maxlength="11">

        @if ($isNew)
            <label><input type="checkbox" name="for_statements" value="1" @checked(old('for_statements'))> {{ __($b.'make_for_statements') }}</label>
        @endif

        <div class="actions">
            <button type="submit">{{ __('registry.save') }}</button>
            <a href="/company" style="margin-top: 18px;">{{ __('registry.cancel') }}</a>
        </div>
    </form>
@endsection
