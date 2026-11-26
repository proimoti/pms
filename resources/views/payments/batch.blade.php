@extends('layouts.app')

@section('title', __('payments.batch.title'))
@section('width', 'list')

@section('content')
    <h1>{{ __('payments.batch.title') }}</h1>
    <p class="muted">{{ __('payments.batch.intro') }}</p>

    @php($f = 'payments.fields.')
    @php($b = 'payments.batch.')
    @php($rows = old('rows') ?: array_fill(0, 5, []))
    <form method="POST" action="/payments/batch" id="batch-form">
        @csrf
        <h2 style="margin-top: 0;">{{ __($b.'common') }}</h2>
        <div class="grid">
            <div>
                <label for="paid_on">{{ __($f.'paid_on') }} *</label>
                <input id="paid_on" type="text" name="paid_on" value="{{ old('paid_on', now()->format('d.m.Y')) }}" required>
            </div>
            <div>
                <label for="method">{{ __($f.'method') }} *</label>
                <select id="method" name="method">
                    @foreach (['bank', 'cash'] as $method)
                        <option value="{{ $method }}" @selected(old('method', 'bank') === $method)>{{ __('payments.methods.'.$method) }}</option>
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

        <datalist id="leases">
            @foreach ($leases as $lease)
                <option value="{{ $lease->number }} — {{ $lease->tenant?->name }}"></option>
            @endforeach
        </datalist>

        <div class="table-wrap" style="margin-top: 16px;">
            <table>
                <thead>
                <tr>
                    <th>{{ __($b.'contract') }}</th><th>{{ __($f.'amount') }}</th><th>{{ __($f.'reference') }}</th>
                    <th>{{ __($f.'paid_on') }}</th><th>{{ __($f.'method') }}</th><th>{{ __($f.'bank_account_id') }}</th>
                </tr>
                </thead>
                <tbody id="batch-rows">
                @foreach (array_values($rows) as $i => $row)
                    @include('payments.batch-row', ['i' => $i, 'row' => $row])
                @endforeach
                </tbody>
            </table>
        </div>
        @error('rows')<div class="errors" style="margin-top: 8px;">{{ $message }}</div>@enderror

        <template id="batch-row-template">@include('payments.batch-row', ['i' => '__N__', 'row' => []])</template>
        <div class="actions">
            <button type="button" class="secondary" id="add-row">{{ __($b.'add_row') }}</button>
            <button type="submit">{{ __($b.'save') }}</button>
            <a href="/payments" style="margin-top: 18px;">{{ __('registry.cancel') }}</a>
        </div>
    </form>

    <script>
        document.getElementById('add-row').addEventListener('click', function () {
            const body = document.getElementById('batch-rows');
            const html = document.getElementById('batch-row-template').innerHTML.replaceAll('__N__', String(body.rows.length));
            body.insertAdjacentHTML('beforeend', html);
        });
    </script>
@endsection
