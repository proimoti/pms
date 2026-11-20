@extends('layouts.app')

@php($heading = __('registry.one.lease-contracts').' № '.$contract->number)
@section('title', $heading)
@section('width', 'wide')

@section('content')
    <h1>{{ $heading }}</h1>

    {{-- Информационните полета се поправят винаги (с журнал). Наемателят, имотите,
         наемът и депозитът зависят от сумите: с анекс или с „Поправка“ (PROJECT.md, „Поправки“). --}}
    <dl>
        <dt>{{ __('contracts.screens.fields.tenant') }}</dt><dd>№ {{ $contract->tenant?->number }} {{ $contract->tenant?->name }}</dd>
    </dl>
    <p class="muted">
        {{ __('contracts.screens.hints.tenant_locked') }}
        <a href="/lease-contracts/{{ $contract->id }}/correct">{{ __('contracts.screens.correction') }}</a>.
        {{ __('contracts.screens.hints.lease_amount_fields') }}
    </p>

    <form method="POST" action="/lease-contracts/{{ $contract->id }}">
        @csrf
        @method('PUT')

        <div class="grid">
            <div>
                <label for="number">{{ __('contracts.screens.fields.number') }} *</label>
                <input id="number" type="number" min="1" name="number" value="{{ old('number', $contract->number) }}" required>
            </div>
            <div>
                <label for="signed_on">{{ __('contracts.screens.fields.signed_on') }} *</label>
                <input id="signed_on" type="text" inputmode="numeric" maxlength="10" autocomplete="off" placeholder="{{ __('registry.date_placeholder') }}" name="signed_on" value="{{ \App\Support\BgDate::toInput(old('signed_on', $contract->signed_on)) }}" required>
            </div>
            <div>
                <label for="end_on">{{ __('contracts.screens.fields.end_on') }}</label>
                @if ($endLocked)
                    <input id="end_on" type="text" value="{{ \App\Support\BgDate::toInput($contract->end_on) }}" disabled>
                    <div class="muted">{{ __('contracts.screens.hints.end_on_locked') }}</div>
                @else
                    <input id="end_on" type="text" inputmode="numeric" maxlength="10" autocomplete="off" placeholder="{{ __('registry.date_placeholder') }}" name="end_on" value="{{ \App\Support\BgDate::toInput(old('end_on', $contract->end_on)) }}">
                @endif
            </div>
            <div>
                <label for="terminated_on">{{ __('contracts.screens.fields.terminated_on') }}</label>
                <input id="terminated_on" type="text" inputmode="numeric" maxlength="10" autocomplete="off" placeholder="{{ __('registry.date_placeholder') }}" name="terminated_on" value="{{ \App\Support\BgDate::toInput(old('terminated_on', $contract->terminated_on)) }}">
                <div class="muted">{{ __('contracts.screens.hints.terminated_on') }}</div>
            </div>
        </div>

        <label for="note">{{ __('contracts.screens.fields.note') }}</label>
        <textarea id="note" name="note">{{ old('note', $contract->note) }}</textarea>

        <div class="actions">
            <button type="submit">{{ __('registry.save') }}</button>
            <a href="/lease-contracts/{{ $contract->id }}" style="margin-top: 18px;">{{ __('registry.cancel') }}</a>
        </div>
    </form>
@endsection
