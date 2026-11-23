@extends('layouts.app')

@section('title', __('utilities.import.preview_title'))
@section('width', 'board')

@section('content')
    <h1>{{ __('utilities.import.preview_title') }}</h1>
    <p>{{ $fileName }} <span class="muted">({{ __('utilities.sources.'.$preview->source) }})</span></p>

    <div class="actions">
        <form method="POST" action="/utility-imports">
            @csrf
            <input type="hidden" name="token" value="{{ $token }}">
            <button type="submit" data-once>{{ __('utilities.import.confirm') }}</button>
        </form>
        <form method="POST" action="/utility-imports/cancel">
            @csrf
            <input type="hidden" name="token" value="{{ $token }}">
            <button type="submit" class="secondary">{{ __('utilities.import.cancel') }}</button>
        </form>
    </div>

    @if ($preview->skippedFees > 0)
        <p class="muted">{{ __('utilities.import.skipped_fees', ['count' => $preview->skippedFees]) }}</p>
    @endif

    <h2>{{ __('utilities.import.recognised') }} ({{ count($preview->bills) }})</h2>
    @if ($preview->bills === [])
        <p class="muted">{{ __('utilities.import.nothing') }}</p>
    @else
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>{{ __('utilities.import.paid_at') }}</th>
                    <th>{{ __('utilities.fields.provider') }}</th>
                    <th>{{ __('utilities.fields.number') }}</th>
                    <th>{{ __('utilities.import.parts') }}</th>
                    <th class="num">{{ __('utilities.import.amount') }}</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($preview->bills as $bill)
                    <tr>
                        <td>{{ $bill->row->paidAt?->format('d.m.Y') }}</td>
                        <td>{{ $bill->row->providerName }}</td>
                        <td>{{ $bill->account->number }}</td>
                        <td>
                            @if ($bill->account->paid_from_fund)
                                {{ __('utilities.import.from_fund', ['fund' => $bill->account->fund?->name]) }}
                            @endif
                            @foreach ($bill->parts as $propertyId => $amount)
                                № {{ $properties[$propertyId] ?? $propertyId }}@if (count($bill->parts) > 1): {{ \App\Support\Format::money($amount) }}@endif{{ $loop->last ? '' : ',' }}
                            @endforeach
                        </td>
                        <td class="num">{{ \App\Support\Format::money(\App\Services\Charges\Cents::format($bill->row->amountCents)) }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif

    <h2>{{ __('utilities.import.for_decision') }} ({{ count($preview->issues) }})</h2>
    @if ($preview->issues === [])
        <p class="muted">{{ __('utilities.import.nothing') }}</p>
    @else
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>{{ __('utilities.import.paid_at') }}</th>
                    <th>{{ __('utilities.import.provider_in_file') }}</th>
                    <th>{{ __('utilities.import.number_in_file') }}</th>
                    <th>{{ __('utilities.import.reason') }}</th>
                    <th class="num">{{ __('utilities.import.amount') }}</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($preview->issues as $issue)
                    <tr>
                        <td>{{ $issue->row->paidAt?->format('d.m.Y') }}</td>
                        <td>{{ $issue->row->providerName }}</td>
                        <td>{{ $issue->row->number }}</td>
                        <td>{{ __('utilities.reasons.'.$issue->reason) }}</td>
                        <td class="num">{{ $issue->row->amountCents === null ? '' : \App\Support\Format::money(\App\Services\Charges\Cents::format($issue->row->amountCents)) }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif

    <h2>{{ __('utilities.import.duplicates') }} ({{ count($preview->duplicates) }})</h2>
    @if ($preview->duplicates === [])
        <p class="muted">{{ __('utilities.import.nothing') }}</p>
    @else
        <p class="muted">
            @foreach ($preview->duplicates as $row)
                {{ $row->reference }}{{ $loop->last ? '' : ',' }}
            @endforeach
        </p>
    @endif
@endsection
