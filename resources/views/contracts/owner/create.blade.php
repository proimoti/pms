@extends('layouts.app')

@section('title', __('registry.new.owner-contracts'))
@section('width', 'full')

@section('content')
    <h1>{{ __('registry.new.owner-contracts') }}</h1>

    <form method="POST" action="/owner-contracts" id="contract-form">
        @csrf

        <div class="grid">
            <div>
                <label for="type">{{ __('contracts.screens.fields.type') }} *</label>
                <select id="type" name="type" required>
                    <option value=""></option>
                    @foreach ($types as $type)
                        <option value="{{ $type->value }}" @selected(old('type') === $type->value)>{{ __('contracts.types.'.$type->value) }}</option>
                    @endforeach
                </select>
                <div class="muted">{{ __('contracts.screens.hints.type_locked') }}</div>
            </div>
            <div>
                <label for="number">{{ __('contracts.screens.fields.number') }}</label>
                <input id="number" type="number" min="1" name="number" value="{{ old('number') }}">
                <div class="muted">{{ __('contracts.screens.hints.number') }}</div>
            </div>
            <div>
                <label for="owner_id">{{ __('contracts.screens.fields.owner') }}</label>
                <div class="with-new">
                <select data-quick-section="owners" id="owner_id" name="owner_id">
                    <option value="">{{ __('registry.none') }}</option>
                    @foreach ($owners as $owner)
                        <option value="{{ $owner->id }}" @selected((string) old('owner_id') === (string) $owner->id)>
                            № {{ $owner->number }} {{ $owner->name }} ({{ __('registry.kinds.'.$owner->kind->value) }})
                        </option>
                    @endforeach
                </select>
                    @include('contracts.partials.new-button', ['section' => 'owners'])
                </div>
            </div>
            <div>
                <label for="signed_on">{{ __('contracts.screens.fields.signed_on') }} *</label>
                <x-date-input id="signed_on" name="signed_on" required :value="old('signed_on')" />
                <div class="muted">{{ __('contracts.screens.hints.signed_on') }}</div>
            </div>
            <div>
                <label for="end_on">{{ __('contracts.screens.fields.end_on') }}</label>
                <x-date-input id="end_on" name="end_on" :value="old('end_on')" />
                <div class="muted">{{ __('contracts.screens.hints.end_on') }}</div>
            </div>
        </div>

        <div class="grid">
            <div data-calc="percent">
                <label for="commission_percent">{{ __('contracts.screens.fields.commission_percent') }}</label>
                <input id="commission_percent" type="text" inputmode="decimal" name="commission_percent" value="{{ old('commission_percent') }}">
                <div class="muted">{{ __('contracts.screens.hints.percent_only') }}</div>
            </div>
            <div data-calc="percent fixed">
                <label for="payment_method">{{ __('contracts.screens.fields.payment_method') }}</label>
                <select id="payment_method" name="payment_method">
                    <option value=""></option>
                    @foreach ($methods as $method)
                        <option value="{{ $method->value }}" @selected(old('payment_method') === $method->value)>{{ __('contracts.payment_methods.'.$method->value) }}</option>
                    @endforeach
                </select>
            </div>
            <div data-calc="percent fixed">
                <label for="beneficiary_id">{{ __('contracts.screens.fields.beneficiary') }}</label>
                @include('contracts.owner.beneficiary-select', [
                    'id' => 'beneficiary_id', 'name' => 'beneficiary_id',
                    'selected' => old('beneficiary_id', ''), 'ownerId' => null,
                ])
                @include('contracts.owner.new-beneficiary', ['target' => 'beneficiary_id'])
            </div>
        </div>

        <h2>{{ __('contracts.screens.sections.lines') }}</h2>
        <p class="muted">{{ __('contracts.screens.hints.lines') }} {{ __('contracts.screens.hints.start_on') }}</p>
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>{{ __('contracts.screens.fields.property') }}</th>
                    <th>{{ __('contracts.screens.fields.start_on') }}</th>
                    <th data-calc="fixed">{{ __('contracts.screens.fields.fixed_amount') }}</th>
                </tr>
                </thead>
                <tbody id="lines">
                @for ($i = 0; $i < $lineCount; $i++)
                    @include('contracts.owner.line-row', ['index' => $i])
                @endfor
                </tbody>
            </table>
        </div>
        <button type="button" class="secondary" id="add-line">{{ __('contracts.screens.add_line') }}</button>

        <label for="note">{{ __('contracts.screens.fields.note') }}</label>
        <textarea id="note" name="note">{{ old('note') }}</textarea>

        <div class="actions">
            <button type="submit">{{ __('registry.save') }}</button>
            <a href="/owner-contracts" style="margin-top: 18px;">{{ __('registry.cancel') }}</a>
        </div>
    </form>

    {{-- Образец за нов ред; бутонът „Добави още имоти“ го копира, без ограничение в броя. --}}
    <template id="line-template">
        @include('contracts.owner.line-row', ['index' => '__INDEX__'])
    </template>

    {{-- Неприложимите полета за избрания тип се скриват и не се изпращат (PROJECT.md, т. 2 „Договори“). --}}
    <script>
        (function () {
            const calculation = @js(collect($types)->mapWithKeys(fn ($t) => [$t->value => $t->calculation()->value]));
            const type = document.getElementById('type');
            const lines = document.getElementById('lines');
            const template = document.getElementById('line-template');
            let next = {{ $lineCount }};

            document.getElementById('add-line').addEventListener('click', function () {
                lines.insertAdjacentHTML('beforeend', template.innerHTML.replaceAll('__INDEX__', String(next++)));
                update();
                lines.lastElementChild.querySelector('select').focus();
            });

            function update() {
                const current = calculation[type.value] ?? null;
                document.querySelectorAll('#contract-form [data-calc]').forEach(function (element) {
                    const fits = current !== null && element.dataset.calc.split(' ').includes(current);
                    element.style.display = fits ? '' : 'none';
                    element.querySelectorAll('input, select').forEach(function (input) { input.disabled = !fits; });
                });
            }

            type.addEventListener('change', update);
            update();

            // Записът, свързан с избрания собственик, е „Самият собственик“: не се показва втори път.
            const owner = document.getElementById('owner_id');
            const beneficiary = document.getElementById('beneficiary_id');

            function hideOwnerHimself() {
                beneficiary.querySelectorAll('option[data-owner]').forEach(function (option) {
                    const same = option.dataset.owner === owner.value;
                    option.hidden = same;
                    if (same && option.selected) {
                        beneficiary.value = '';
                    }
                });
            }

            owner.addEventListener('change', hideOwnerHimself);
            hideOwnerHimself();
        })();
    </script>

    @include('contracts.partials.quick-new')
@endsection
