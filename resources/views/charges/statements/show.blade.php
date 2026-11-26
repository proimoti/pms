@extends('layouts.app')

@use('App\Support\Format')
@use('App\Services\Charges\Month')

@php($heading = __('charges.statements.notice').' № '.$statement->number)
@section('title', $heading)
@section('width', 'wide')

@section('content')
    <div class="row">
        <h1 style="margin: 0;">{{ $heading }}</h1>
        <div class="actions">
            <a class="button secondary" style="margin-top: 0;" href="/statements/{{ $statement->id }}/pdf">{{ __('statements.pdf.download') }}</a>
            {{-- Изпращане (24.11.2026): само управляващият; повторно — с потвърждение. --}}
            @if ($canManage)
                <form method="POST" action="/statements/{{ $statement->id }}/send" class="actions">
                    @csrf
                    @if ($alreadySent)
                        <label style="margin: 0; font-weight: normal;"><input type="checkbox" name="confirm_resend" value="1" required> {{ __('statements.send.confirm_resend') }}</label>
                    @endif
                    <button type="submit" style="margin-top: 0;">{{ $alreadySent ? __('statements.send.resend') : __('statements.send.button') }}</button>
                </form>
            @endif
        </div>
    </div>
    <p class="muted">{{ __('charges.statements.not_invoice') }}</p>
    <p>{{ __('payments.column') }}: @include('payments.status', ['info' => $paid])</p>
    @if (($statement->snapshot['bank'] ?? null) === null)
        <div class="warning">{{ __('statements.pdf.no_bank_warning') }}</div>
    @endif

    <dl>
        <dt>{{ __('charges.fields.month') }}</dt><dd>{{ Month::label($statement->month) }}</dd>
        <dt>{{ __('charges.fields.tenant') }}</dt><dd>{{ $statement->tenant?->name }}</dd>
        <dt>{{ __('charges.fields.lease_contract') }}</dt>
        <dd><a href="/lease-contracts/{{ $statement->lease_contract_id }}">№ {{ $statement->leaseContract?->number }}</a></dd>
        <dt>{{ __('charges.statements.issued_at') }}</dt><dd>{{ $statement->issued_at->format('d.m.Y') }} ({{ $statement->issuedBy?->name }})</dd>
    </dl>

    <div class="table-wrap">
        <table>
            <thead>
            <tr>
                <th>{{ __('charges.fields.property') }}</th>
                <th>{{ __('charges.fields.type') }}</th>
                <th class="num">{{ __('charges.fields.days') }}</th>
                <th class="num">{{ __('charges.fields.amount') }}</th>
                <th>{{ __('charges.fields.note') }}</th>
            </tr>
            </thead>
            <tbody>
            @foreach ($statement->lines as $line)
                <tr @class(['zero' => (float) $line->amount == 0])>
                    <td>№ {{ $line->property?->number }} {{ \App\Support\Address::format($line->property) }}</td>
                    <td>{{ __('charges.types.'.$line->type->value) }}</td>
                    <td class="num">{{ $line->days }}</td>
                    <td class="num">{{ Format::money($line->amount) }}</td>
                    <td>
                        @if ($line->isUtility())
                            @include('charges.partials.utility-label', ['row' => $line, 'details' => true])
                        @endif
                        {{ $line->note }}
                    </td>
                </tr>
            @endforeach
            </tbody>
            <tfoot>
            <tr>
                <th colspan="3">{{ __('charges.statements.total') }}</th>
                <th class="num">{{ Format::money($statement->total) }}</th>
                <th></th>
            </tr>
            </tfoot>
        </table>
    </div>

    {{-- Изпращанията от email_log (без съдържанието). --}}
    @if ($sends->isNotEmpty())
        @php($sf = 'statements.send.fields.')
        <h2>{{ __('statements.send.history') }}</h2>
        <div class="table-wrap">
            <table>
                <thead>
                <tr><th>{{ __($sf.'date') }}</th><th>{{ __($sf.'intended') }}</th><th>{{ __($sf.'delivered') }}</th><th>{{ __($sf.'mode') }}</th><th>{{ __($sf.'result') }}</th><th>{{ __($sf.'user') }}</th></tr>
                </thead>
                <tbody>
                @foreach ($sends as $log)
                    <tr>
                        <td class="nowrap">{{ $log->sent_at->format('d.m.Y H:i') }}</td>
                        <td>{{ $log->intended_to ?? __('registry.none') }}</td>
                        <td>{{ $log->delivered_to ?? __('registry.none') }}</td>
                        <td>{{ $log->mode ? __('statements.send.modes.'.$log->mode) : __('registry.none') }}</td>
                        <td>{{ __('statements.send.results.'.$log->status) }}@if ($log->error) <div class="muted">{{ $log->error }}</div>@endif</td>
                        <td>{{ $log->user?->name }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif
@endsection
