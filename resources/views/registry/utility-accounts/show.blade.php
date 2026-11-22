@extends('layouts.app')

@section('title', __('registry.one.utility-accounts').' '.($account->number ?? ''))
@section('width', 'wide')

@section('content')
    <h1>{{ __('registry.one.utility-accounts') }} {{ $account->number }}</h1>

    <dl>
        <dt>{{ __('utilities.fields.provider') }}</dt><dd>{{ $account->provider?->name ?? __('registry.none') }}</dd>
        <dt>{{ __('utilities.fields.number') }}</dt><dd>{{ $account->number ?? __('registry.none') }}</dd>
        <dt>{{ __('utilities.fields.kind') }}</dt><dd>{{ __('utilities.kinds.'.$account->kind->value) }}</dd>
        <dt>{{ __('utilities.fields.billing') }}</dt>
        <dd>
            {{ __('utilities.billing.'.$account->billing->value) }}
            @if ($account->isFixed()) · {{ \App\Support\Format::money($account->fixed_amount) }} € @endif
        </dd>
        @unless ($account->isFixed())
            <dt>{{ __('utilities.fields.period') }}</dt>
            <dd>{{ $account->period_start_day }} – {{ $account->period_end_day ?? __('utilities.hints.last_day') }}</dd>
        @endunless
        <dt>{{ __('utilities.fields.valid_from') }}</dt><dd>{{ \App\Support\Format::date($account->valid_from) }}</dd>
        <dt>{{ __('utilities.fields.valid_to') }}</dt><dd>{{ \App\Support\Format::date($account->valid_to) }}</dd>
        <dt>{{ __('utilities.fields.note') }}</dt><dd style="white-space: pre-line;">{{ $account->note ?? __('registry.none') }}</dd>
    </dl>

    <h2>{{ __('utilities.fields.properties') }}</h2>
    <div class="table-wrap">
        <table>
            <thead>
            <tr>
                <th>{{ __('utilities.fields.property') }}</th>
                <th class="num">{{ __('utilities.fields.share') }}</th>
            </tr>
            </thead>
            <tbody>
            @foreach ($account->properties as $line)
                <tr>
                    <td>
                        @if ($line->property)
                            <a href="/properties/{{ $line->property_id }}">№ {{ $line->property->number }}</a> {{ \App\Support\Address::format($line->property) }}
                        @endif
                    </td>
                    <td class="num">{{ \App\Support\Format::percent($line->share) }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
    @if ($account->properties->count() > 1)
        <p class="muted">{{ __('utilities.hints.remainder', ['number' => $account->properties->last()->property?->number]) }}</p>
    @endif

    @include('registry.partials.record-actions', ['section' => 'utility-accounts', 'record' => $account])
@endsection
