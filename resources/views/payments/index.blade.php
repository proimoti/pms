@extends('layouts.app')

@use('App\Support\Format')

@section('title', __('registry.sections.payments'))
@section('width', 'list')

@section('content')
    <div class="row">
        <h1 style="margin: 0;">{{ __('registry.sections.payments') }}</h1>
        @if ($canPay)
            <a class="button" style="margin-top: 0;" href="/payments/batch">{{ __('payments.batch.open') }}</a>
        @endif
    </div>
    <p class="muted">{{ __('payments.list.intro') }}</p>

    @php($l = 'payments.list.')
    <form method="GET" action="/payments" class="row" style="justify-content: flex-start; align-items: flex-end; margin-bottom: 14px;">
        <div><label for="from">{{ __($l.'from') }}</label><input id="from" type="text" name="from" value="{{ $filters['from'] }}" style="max-width: 130px;"></div>
        <div><label for="to">{{ __($l.'to') }}</label><input id="to" type="text" name="to" value="{{ $filters['to'] }}" style="max-width: 130px;"></div>
        <div>
            <label for="method">{{ __('payments.fields.method') }}</label>
            <select id="method" name="method">
                <option value="">{{ __($l.'all') }}</option>
                @foreach (['bank', 'cash'] as $method)
                    <option value="{{ $method }}" @selected($filters['method'] === $method)>{{ __('payments.methods.'.$method) }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="bank_account_id">{{ __('payments.fields.bank_account_id') }}</label>
            <select id="bank_account_id" name="bank_account_id">
                <option value="">{{ __($l.'all') }}</option>
                @foreach ($accounts as $account)
                    <option value="{{ $account->id }}" @selected($filters['bank_account_id'] === $account->id)>{{ $account->formattedIban() }}</option>
                @endforeach
            </select>
        </div>
        <button type="submit" class="secondary" style="margin-top: 0;">{{ __('reports.show') }}</button>
    </form>

    @if ($payments->isEmpty())
        <p class="muted">{{ __($l.'empty') }}</p>
    @endif
    @php($f = 'payments.fields.')
    <div class="table-wrap">
        <table>
            <thead>
            <tr>
                <th>{{ __($f.'paid_on') }}</th><th>{{ __($l.'contract') }}</th><th>{{ __($l.'tenant') }}</th><th class="num">{{ __($f.'amount') }}</th>
                <th>{{ __($f.'method') }}</th><th>{{ __($f.'bank_account_id') }}</th><th>{{ __($f.'reference') }}</th><th>{{ __($f.'created_by') }}</th>
            </tr>
            </thead>
            <tbody>
            @foreach ($payments as $payment)
                <tr data-href="/lease-contracts/{{ $payment->lease_contract_id }}">
                    <td class="nowrap">{{ $payment->paid_on->format('d.m.Y') }}</td>
                    <td class="nowrap"><a href="/lease-contracts/{{ $payment->lease_contract_id }}">№ {{ $payment->leaseContract?->number }}</a></td>
                    <td>{{ $payment->leaseContract?->tenant?->name }}</td>
                    <td class="num">{{ Format::money($payment->amount) }}</td>
                    <td>{{ __('payments.methods.'.$payment->method) }}</td>
                    <td class="nowrap">{{ $payment->bankAccount?->formattedIban() }}</td>
                    <td>
                        @if ($payment->isReversal())
                            <span class="badge state-archived">{{ __($l.'reversal') }}</span> {{ $payment->reason }}
                        @else
                            {{ $payment->reference }}
                        @endif
                    </td>
                    <td>{{ $payment->createdBy?->name }}</td>
                </tr>
            @endforeach
            </tbody>
            <tfoot>
            <tr><th colspan="3">{{ __($l.'total') }}</th><td class="num">{{ Format::money($total) }}</td><td colspan="4"></td></tr>
            </tfoot>
        </table>
    </div>
@endsection
