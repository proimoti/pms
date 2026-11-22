@extends('layouts.app')

@php($isNew = ! $account->exists)
@php($heading = $isNew ? __('registry.new.utility-accounts') : __('registry.one.utility-accounts').' '.$account->number)
@section('title', $heading)
@section('width', 'wide')

@section('content')
    <h1>{{ $heading }}</h1>

    <form method="POST" action="{{ $isNew ? '/utility-accounts' : '/utility-accounts/'.$account->id }}">
        @csrf
        @unless ($isNew) @method('PUT') @endunless

        <div class="grid">
            <div>
                <label for="kind">{{ __('utilities.fields.kind') }} *</label>
                <select id="kind" name="kind" required>
                    <option value=""></option>
                    @foreach (\App\Enums\UtilityKind::cases() as $kind)
                        <option value="{{ $kind->value }}" @selected(old('kind', $account->kind?->value) === $kind->value)>{{ __('utilities.kinds.'.$kind->value) }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="billing">{{ __('utilities.fields.billing') }} *</label>
                <select id="billing" name="billing" required>
                    @foreach (\App\Enums\UtilityBilling::cases() as $billing)
                        <option value="{{ $billing->value }}" @selected(old('billing', $account->billing?->value ?? 'actual') === $billing->value)>{{ __('utilities.billing.'.$billing->value) }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="fixed_amount">{{ __('utilities.fields.fixed_amount') }}</label>
                <input id="fixed_amount" type="text" inputmode="decimal" name="fixed_amount" value="{{ old('fixed_amount', $account->fixed_amount) }}">
            </div>
        </div>
        <p class="muted">{{ __('utilities.hints.billing') }}</p>

        <div class="grid">
            <div>
                <label for="provider_id">{{ __('utilities.fields.provider') }}</label>
                <select id="provider_id" name="provider_id">
                    <option value=""></option>
                    @foreach ($providers as $provider)
                        <option value="{{ $provider->id }}" @selected((string) old('provider_id', $account->provider_id) === (string) $provider->id)>{{ $provider->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="number">{{ __('utilities.fields.number') }}</label>
                <input id="number" type="text" name="number" value="{{ old('number', $account->number) }}">
            </div>
        </div>
        <p class="muted">{{ __('utilities.hints.number') }}</p>

        <div class="grid">
            <div>
                <label for="period_start_day">{{ __('utilities.fields.period_start_day') }}</label>
                <input id="period_start_day" type="number" min="1" max="31" name="period_start_day" value="{{ old('period_start_day', $account->period_start_day) }}">
            </div>
            <div>
                <label for="period_end_day">{{ __('utilities.fields.period_end_day') }}</label>
                <input id="period_end_day" type="number" min="1" max="31" name="period_end_day" value="{{ old('period_end_day', $account->period_end_day) }}" placeholder="{{ __('utilities.hints.last_day') }}">
            </div>
            <div>
                <label for="valid_from">{{ __('utilities.fields.valid_from') }} *</label>
                <x-date-input id="valid_from" name="valid_from" required :value="old('valid_from', $account->valid_from)" />
            </div>
            <div>
                <label for="valid_to">{{ __('utilities.fields.valid_to') }}</label>
                <x-date-input id="valid_to" name="valid_to" :value="old('valid_to', $account->valid_to)" />
            </div>
        </div>
        <p class="muted">{{ __('utilities.hints.period') }}</p>

        <h2>{{ __('utilities.fields.properties') }} *</h2>
        <p class="muted">{{ __('utilities.hints.shares') }}</p>
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>{{ __('utilities.fields.property') }}</th>
                    <th>{{ __('utilities.fields.share') }}</th>
                </tr>
                </thead>
                <tbody id="property-rows">
                @foreach ($rows as $index => $row)
                    @include('registry.utility-accounts.property-row', ['index' => $index, 'row' => $row])
                @endforeach
                </tbody>
            </table>
        </div>
        <p class="muted" id="shares-total" aria-live="polite"></p>
        <div class="actions">
            <button type="button" class="secondary" id="add-property">{{ __('utilities.add_property') }}</button>
            <button type="button" class="secondary" id="split-equally">{{ __('utilities.split_equally') }}</button>
        </div>

        <label for="note">{{ __('utilities.fields.note') }}</label>
        <textarea id="note" name="note">{{ old('note', $account->note) }}</textarea>

        <div class="actions">
            <button type="submit">{{ __('registry.save') }}</button>
            <a href="{{ $isNew ? '/utility-accounts' : '/utility-accounts/'.$account->id }}" style="margin-top: 18px;">{{ __('registry.cancel') }}</a>
        </div>
    </form>

    {{-- Образец за нов ред; бутонът „Добави още имот“ го копира. --}}
    <template id="property-template">
        @include('registry.utility-accounts.property-row', ['index' => '__INDEX__', 'row' => ['property_id' => '', 'share' => '']])
    </template>

    <script>
        (function () {
            const rows = document.getElementById('property-rows');
            const template = document.getElementById('property-template');
            let next = {{ count($rows) }};

            const totalLine = document.getElementById('shares-total');
            const texts = @json(['entered' => __('utilities.shares_entered'), 'over' => __('utilities.shares_over'), 'ok' => __('utilities.shares_ok')]);
            const format = (hundredths) => (hundredths / 100).toFixed(2).replace('.', ',');

            // Редовете с избран имот; делът се пише само при два или повече.
            function chosen() {
                return Array.from(rows.querySelectorAll('tr')).filter((row) => row.querySelector('select').value !== '');
            }

            // Сборът докато се пише (сметнат в стотни от процента, без грешки от закръгляне).
            function showTotal() {
                const lines = chosen();
                if (lines.length < 2) {
                    totalLine.textContent = '';
                    return;
                }
                let total = 0;
                lines.forEach((row) => {
                    const value = parseFloat(row.querySelector('input').value.replace(',', '.').trim());
                    total += isNaN(value) ? 0 : Math.round(value * 100);
                });
                totalLine.textContent = total === 10000 ? texts.ok
                    : total < 10000 ? texts.entered.replace(':total', format(total)).replace(':left', format(10000 - total))
                    : texts.over.replace(':total', format(total)).replace(':over', format(total - 10000));
            }

            // „Раздели поравно“: по равно на всеки, остатъкът към последния (33,33 / 33,33 / 33,34).
            document.getElementById('split-equally').addEventListener('click', function () {
                const lines = chosen();
                if (lines.length < 2) {
                    return;
                }
                const each = Math.floor(10000 / lines.length);
                lines.forEach((row, index) => {
                    const share = index === lines.length - 1 ? 10000 - each * (lines.length - 1) : each;
                    row.querySelector('input').value = format(share);
                });
                showTotal();
            });

            document.getElementById('add-property').addEventListener('click', function () {
                rows.insertAdjacentHTML('beforeend', template.innerHTML.replaceAll('__INDEX__', String(next++)));
                rows.lastElementChild.querySelector('select').focus();
            });
            rows.addEventListener('input', showTotal);
            rows.addEventListener('change', showTotal);
            showTotal();
        })();
    </script>
@endsection
