@extends('layouts.app')

@use('App\Support\Format')
@use('App\Enums\CalculationMethod')

@php($heading = __('contracts.screens.new_annex').' — '.__('registry.one.owner-contracts').' № '.$contract->number)
@php($fixed = $contract->calculation === CalculationMethod::Fixed)
@php($actions = $fixed ? ['amount', 'add', 'remove'] : ['add', 'remove'])
@section('title', $heading)
@section('width', 'full')

@section('content')
    <h1>{{ $heading }}</h1>
    <p class="muted">{{ __('contracts.screens.hints.annex') }}</p>

    <form method="POST" action="/owner-contracts/{{ $contract->id }}/annexes">
        @csrf

        <div class="grid">
            <div>
                <label for="number">{{ __('contracts.screens.fields.annex_number') }}</label>
                <input id="number" type="number" min="1" name="number" value="{{ old('number', $nextNumber) }}">
            </div>
            <div>
                <label for="signed_on">{{ __('contracts.screens.fields.annex_signed_on') }} *</label>
                <x-date-input id="signed_on" name="signed_on" required :value="old('signed_on')" />
            </div>
            <div>
                <label for="from">{{ __('contracts.screens.fields.annex_from') }}</label>
                <x-date-input id="from" name="from" :value="old('from')" />
                <div class="muted">{{ __('contracts.screens.hints.annex_from') }}</div>
            </div>
        </div>

        @if ($contract->calculation === CalculationMethod::Percent)
            <h2>{{ __('contracts.screens.fields.commission_percent') }}</h2>
            <label for="commission_percent">{{ __('contracts.screens.fields.new_percent') }}
                <span class="muted">({{ __('contracts.screens.now') }} {{ Format::percent($contract->commissionOn(today())) }})</span></label>
            <input id="commission_percent" type="text" inputmode="decimal" name="commission_percent" value="{{ old('commission_percent') }}" style="max-width: 200px;">
            <div class="muted">{{ __('contracts.screens.hints.empty_no_change') }}</div>
        @endif

        @if ($contract->calculation !== CalculationMethod::Own)
            <h2>{{ __('contracts.screens.sections.payees') }}</h2>
            <label style="font-weight: normal;">
                <input type="checkbox" name="change_payee" value="1" @checked(old('change_payee'))> {{ __('contracts.screens.change_payee') }}
            </label>
            <div class="grid">
                <div>
                    <label for="payee_beneficiary">{{ __('contracts.screens.fields.beneficiary') }}</label>
                    @include('contracts.owner.beneficiary-select', [
                        'id' => 'payee_beneficiary', 'name' => 'payee[beneficiary_id]',
                        'selected' => old('payee.beneficiary_id', ''), 'ownerId' => $contract->owner_id,
                    ])
                    @include('contracts.owner.new-beneficiary', ['target' => 'payee_beneficiary'])
                </div>
                <div>
                    <label for="payee_method">{{ __('contracts.screens.fields.payment_method') }}</label>
                    <select id="payee_method" name="payee[payment_method]">
                        @foreach ($methods as $method)
                            <option value="{{ $method->value }}" @selected(old('payee.payment_method') === $method->value)>{{ __('contracts.payment_methods.'.$method->value) }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
        @endif

        @if ($contract->end_on !== null && $contract->terminated_on === null)
            <h2>{{ __('contracts.screens.sections.extensions') }}</h2>
            <label for="end_on">{{ __('contracts.screens.fields.new_end_on') }}
                <span class="muted">({{ __('contracts.screens.now') }} {{ Format::date($contract->end_on) }})</span></label>
            <x-date-input id="end_on" name="end_on" style="max-width: 200px;" :value="old('end_on')" />
            <div class="muted">{{ __('contracts.screens.hints.empty_no_change') }}</div>
        @endif

        <h2>{{ __('contracts.screens.sections.lines') }}</h2>
        <p class="muted">
            {{ __('contracts.screens.now_in_contract') }}:
            {{ $currentProperties->map(fn ($p) => '№ '.$p->number)->implode(', ') ?: __('registry.none') }}.
            {{ __('contracts.screens.hints.annex_lines') }}
        </p>
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>{{ __('contracts.screens.fields.action') }}</th>
                    <th>{{ __('contracts.screens.fields.property') }}</th>
                    <th>{{ __('contracts.screens.fields.from') }}</th>
                    @if ($fixed)
                        <th>{{ __('contracts.screens.fields.fixed_amount') }}</th>
                    @endif
                </tr>
                </thead>
                <tbody id="lines">
                @for ($i = 0; $i < $lineCount; $i++)
                    @include('contracts.owner.annex-line-row', ['index' => $i])
                @endfor
                </tbody>
            </table>
        </div>
        <button type="button" class="secondary" id="add-line">{{ __('contracts.screens.add_line') }}</button>

        <label for="note">{{ __('contracts.screens.fields.annex_note') }}</label>
        <textarea id="note" name="note">{{ old('note') }}</textarea>

        <div class="actions">
            <button type="submit">{{ __('registry.save') }}</button>
            <a href="/owner-contracts/{{ $contract->id }}" style="margin-top: 18px;">{{ __('registry.cancel') }}</a>
        </div>
    </form>

    <template id="line-template">
        @include('contracts.owner.annex-line-row', ['index' => '__INDEX__'])
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

    @include('contracts.partials.quick-new')
@endsection
