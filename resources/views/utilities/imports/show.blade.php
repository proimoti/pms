@extends('layouts.app')

@section('title', $batch->file_name)
@section('width', 'board')

@section('content')
    <h1>{{ $batch->file_name }}</h1>
    <p class="muted">
        {{ __('utilities.sources.'.$batch->source) }} · {{ $batch->created_at?->format('d.m.Y H:i') }} · {{ $batch->importedBy?->name }}
        @if ($batch->skipped_fees > 0) · {{ __('utilities.import.skipped_fees', ['count' => $batch->skipped_fees]) }} @endif
    </p>

    <h2>{{ __('utilities.import.bills') }} ({{ $batch->bills->count() }})</h2>
    @if ($batch->bills->isEmpty())
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
                    <th>{{ __('utilities.import.reference') }}</th>
                    <th class="num">{{ __('utilities.import.amount') }}</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($batch->bills as $bill)
                    <tr>
                        <td>{{ $bill->paid_at->format('d.m.Y') }}</td>
                        <td>{{ $bill->account?->provider?->name }}</td>
                        <td><a href="/utility-accounts/{{ $bill->utility_account_id }}">{{ $bill->account?->number }}</a></td>
                        <td>
                            @foreach ($bill->parts as $part)
                                № {{ $part->property?->number }}@if ($bill->parts->count() > 1): {{ \App\Support\Format::money($part->amount) }}@endif{{ $loop->last ? '' : ',' }}
                            @endforeach
                        </td>
                        <td>{{ $bill->reference }}</td>
                        <td class="num">{{ \App\Support\Format::money($bill->amount) }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif

    <h2>{{ __('utilities.import.for_decision') }} ({{ $batch->issues->count() }})</h2>
    @if ($batch->issues->isEmpty())
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
                    <th>{{ __('utilities.import.status') }}</th>
                    <th class="num">{{ __('utilities.import.amount') }}</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($batch->issues as $issue)
                    <tr>
                        <td>{{ $issue->paid_at?->format('d.m.Y') }}</td>
                        <td>{{ $issue->provider_name }}</td>
                        <td>{{ $issue->number }}</td>
                        <td>{{ __('utilities.reasons.'.$issue->reason) }}</td>
                        <td>{{ __('utilities.statuses.'.$issue->status) }}</td>
                        <td class="num">{{ $issue->amount === null ? '' : \App\Support\Format::money($issue->amount) }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        <p><a href="/utility-issues">{{ __('registry.sections.utility-issues') }}</a></p>
    @endif

    <p><a href="/utility-imports">{{ __('registry.back_to_list') }}</a></p>
@endsection
