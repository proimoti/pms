@extends('layouts.app')

@section('title', __('registry.new.lease-contracts'))
@section('width', 'full')

@section('content')
    <h1>{{ __('registry.new.lease-contracts') }}</h1>

    <form method="POST" action="/lease-contracts">
        @csrf

        <div class="grid">
            <div>
                <label for="tenant_id">{{ __('contracts.screens.fields.tenant') }} *</label>
                <div class="with-new">
                <select data-quick-section="tenants" id="tenant_id" name="tenant_id" required>
                    <option value=""></option>
                    @foreach ($tenants as $tenant)
                        <option value="{{ $tenant->id }}" @selected((string) old('tenant_id') === (string) $tenant->id)>
                            № {{ $tenant->number }} {{ $tenant->name }} ({{ __('registry.kinds.'.$tenant->kind->value) }})
                        </option>
                    @endforeach
                </select>
                    @include('contracts.partials.new-button', ['section' => 'tenants'])
                </div>
            </div>
            <div>
                <label for="number">{{ __('contracts.screens.fields.number') }}</label>
                <input id="number" type="number" min="1" name="number" value="{{ old('number') }}">
                <div class="muted">{{ __('contracts.screens.hints.number') }}</div>
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

        <h2>{{ __('contracts.screens.sections.lines') }}</h2>
        <p class="muted">{{ __('contracts.screens.hints.lines') }} {{ __('contracts.screens.hints.start_on') }} {{ __('contracts.screens.hints.deposit') }} {{ __('contracts.screens.hints.first_period_rent') }}</p>
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>{{ __('contracts.screens.fields.property') }}</th>
                    <th>{{ __('contracts.screens.fields.start_on') }}</th>
                    <th>{{ __('contracts.screens.fields.rent') }} *</th>
                    <th>{{ __('contracts.screens.fields.deposit') }}</th>
                    <th>{{ __('contracts.screens.fields.first_period_rent') }}</th>
                </tr>
                </thead>
                <tbody id="lines">
                @for ($i = 0; $i < $lineCount; $i++)
                    @include('contracts.lease.line-row', ['index' => $i])
                @endfor
                </tbody>
            </table>
        </div>
        <button type="button" class="secondary" id="add-line">{{ __('contracts.screens.add_line') }}</button>

        <label for="note">{{ __('contracts.screens.fields.note') }}</label>
        <textarea id="note" name="note">{{ old('note') }}</textarea>

        <div class="actions">
            <button type="submit">{{ __('registry.save') }}</button>
            <a href="/lease-contracts" style="margin-top: 18px;">{{ __('registry.cancel') }}</a>
        </div>
    </form>

    {{-- Образец за нов ред; бутонът „Добави още имоти“ го копира, без ограничение в броя. --}}
    <template id="line-template">
        @include('contracts.lease.line-row', ['index' => '__INDEX__'])
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
