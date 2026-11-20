@extends('layouts.app')

@use('App\Support\Format')

@php($heading = __('contracts.screens.new_annex').' — '.__('registry.one.lease-contracts').' № '.$contract->number)
@section('title', $heading)
@section('width', 'full')

@section('content')
    <h1>{{ $heading }}</h1>
    <p class="muted">{{ __('contracts.screens.hints.annex') }}</p>

    <form method="POST" action="/lease-contracts/{{ $contract->id }}/annexes">
        @csrf

        <div class="grid">
            <div>
                <label for="number">{{ __('contracts.screens.fields.annex_number') }}</label>
                <input id="number" type="number" min="1" name="number" value="{{ old('number', $nextNumber) }}">
            </div>
            <div>
                <label for="signed_on">{{ __('contracts.screens.fields.annex_signed_on') }} *</label>
                <input id="signed_on" type="text" inputmode="numeric" maxlength="10" autocomplete="off" placeholder="{{ __('registry.date_placeholder') }}" name="signed_on" value="{{ \App\Support\BgDate::toInput(old('signed_on')) }}" required>
            </div>
            <div>
                <label for="from">{{ __('contracts.screens.fields.annex_from') }}</label>
                <input id="from" type="text" inputmode="numeric" maxlength="10" autocomplete="off" placeholder="{{ __('registry.date_placeholder') }}" name="from" value="{{ \App\Support\BgDate::toInput(old('from')) }}">
                <div class="muted">{{ __('contracts.screens.hints.annex_from') }}</div>
            </div>
        </div>

        <h2>{{ __('contracts.screens.sections.lines') }}</h2>
        <p class="muted">
            {{ __('contracts.screens.now_in_contract') }}:
            {{ $currentLines->map(fn ($line) => '№ '.$line->property->number.' ('.Format::money($line->rent).' / '.Format::money($line->deposit).')')->implode(', ') ?: __('registry.none') }}.
            {{ __('contracts.screens.hints.annex_lines') }} {{ __('contracts.screens.hints.lease_change') }}
        </p>
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>{{ __('contracts.screens.fields.action') }}</th>
                    <th>{{ __('contracts.screens.fields.property') }}</th>
                    <th>{{ __('contracts.screens.fields.from') }}</th>
                    <th>{{ __('contracts.screens.fields.new_rent') }}</th>
                    <th>{{ __('contracts.screens.fields.new_deposit') }}</th>
                </tr>
                </thead>
                <tbody id="lines">
                @for ($i = 0; $i < $lineCount; $i++)
                    @include('contracts.lease.annex-line-row', ['index' => $i])
                @endfor
                </tbody>
            </table>
        </div>
        <button type="button" class="secondary" id="add-line">{{ __('contracts.screens.add_line') }}</button>

        @if ($contract->end_on !== null && $contract->terminated_on === null)
            <h2>{{ __('contracts.screens.sections.extensions') }}</h2>
            <label for="end_on">{{ __('contracts.screens.fields.new_end_on') }}
                <span class="muted">({{ __('contracts.screens.now') }} {{ Format::date($contract->end_on) }})</span></label>
            <input id="end_on" type="text" inputmode="numeric" maxlength="10" autocomplete="off" placeholder="{{ __('registry.date_placeholder') }}" name="end_on" value="{{ \App\Support\BgDate::toInput(old('end_on')) }}" style="max-width: 200px;">
            <div class="muted">{{ __('contracts.screens.hints.empty_no_change') }}</div>
        @endif

        <label for="note">{{ __('contracts.screens.fields.annex_note') }}</label>
        <textarea id="note" name="note">{{ old('note') }}</textarea>

        <div class="actions">
            <button type="submit">{{ __('registry.save') }}</button>
            <a href="/lease-contracts/{{ $contract->id }}" style="margin-top: 18px;">{{ __('registry.cancel') }}</a>
        </div>
    </form>

    <template id="line-template">
        @include('contracts.lease.annex-line-row', ['index' => '__INDEX__'])
    </template>

    <script>
        (function () {
            const lines = document.getElementById('lines');
            const template = document.getElementById('line-template');
            let next = {{ $lineCount }};

            document.getElementById('add-line').addEventListener('click', function () {
                lines.insertAdjacentHTML('beforeend', template.innerHTML.replaceAll('__INDEX__', String(next++)));
                lines.lastElementChild.querySelector('select').focus();
            });
        })();
    </script>
@endsection
