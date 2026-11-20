@extends('layouts.app')

@php($heading = __('registry.one.owner-contracts').' № '.$contract->number)
@section('title', $heading)
@section('width', 'wide')

@section('content')
    <h1>{{ $heading }}</h1>

    {{-- Информационните полета се поправят винаги (с журнал); полетата, от които
         зависят сумите, се сменят с анекс (PROJECT.md, „Поправки“). --}}
    <dl>
        <dt>{{ __('contracts.screens.fields.type') }}</dt><dd>{{ __('contracts.types.'.$contract->type->value) }}</dd>
        <dt>{{ __('contracts.screens.fields.owner') }}</dt><dd>{{ $contract->owner?->name ?? __('contracts.screens.own_company') }}</dd>
    </dl>
    <p class="muted">{{ __('contracts.screens.hints.type_locked') }} {{ __('contracts.screens.hints.amount_fields') }}</p>

    <form method="POST" action="/owner-contracts/{{ $contract->id }}">
        @csrf
        @method('PUT')

        <div class="grid">
            <div>
                <label for="number">{{ __('contracts.screens.fields.number') }} *</label>
                <input id="number" type="number" min="1" name="number" value="{{ old('number', $contract->number) }}" required>
            </div>
            <div>
                <label for="signed_on">{{ __('contracts.screens.fields.signed_on') }} *</label>
                <input id="signed_on" type="text" inputmode="numeric" maxlength="10" autocomplete="off" placeholder="{{ __('registry.date_placeholder') }}" name="signed_on" value="{{ \App\Support\BgDate::toInput(old('signed_on', $contract->signed_on?->toDateString())) }}" required>
            </div>
            <div>
                <label for="end_on">{{ __('contracts.screens.fields.end_on') }}</label>
                @if ($endLocked)
                    <input id="end_on" type="text" inputmode="numeric" maxlength="10" autocomplete="off" placeholder="{{ __('registry.date_placeholder') }}" value="{{ \App\Support\BgDate::toInput($contract->end_on?->toDateString()) }}" disabled>
                    <div class="muted">{{ __('contracts.screens.hints.end_on_locked') }}</div>
                @else
                    <input id="end_on" type="text" inputmode="numeric" maxlength="10" autocomplete="off" placeholder="{{ __('registry.date_placeholder') }}" name="end_on" value="{{ \App\Support\BgDate::toInput(old('end_on', $contract->end_on?->toDateString())) }}">
                @endif
            </div>
            <div>
                <label for="terminated_on">{{ __('contracts.screens.fields.terminated_on') }}</label>
                <input id="terminated_on" type="text" inputmode="numeric" maxlength="10" autocomplete="off" placeholder="{{ __('registry.date_placeholder') }}" name="terminated_on" value="{{ \App\Support\BgDate::toInput(old('terminated_on', $contract->terminated_on?->toDateString())) }}">
                <div class="muted">{{ __('contracts.screens.hints.terminated_on') }}</div>
            </div>
        </div>

        <label for="note">{{ __('contracts.screens.fields.note') }}</label>
        <textarea id="note" name="note">{{ old('note', $contract->note) }}</textarea>

        <div class="actions">
            <button type="submit">{{ __('registry.save') }}</button>
            <a href="/owner-contracts/{{ $contract->id }}" style="margin-top: 18px;">{{ __('registry.cancel') }}</a>
        </div>
    </form>
@endsection
