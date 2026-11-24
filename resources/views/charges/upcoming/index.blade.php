@extends('layouts.app')

@use('App\Support\Format')
@use('App\Services\Charges\Month')

@section('title', __('charges.upcoming.title'))
@section('width', 'list')

@section('content')
    <div class="row">
        <h1 style="margin: 0;">{{ __('charges.upcoming.title') }}</h1>
        <div class="actions">
            @if ($canManage)
                <a class="button" href="/upcoming-charges/create">{{ __('charges.upcoming.new') }}</a>
            @endif
            <a class="button secondary" href="/charges">{{ __('charges.upcoming.back') }}</a>
        </div>
    </div>
    <p class="muted">{{ __('charges.upcoming.intro') }}</p>
    <p>
        @if ($showCancelled)
            <a href="/upcoming-charges">{{ __('charges.upcoming.active') }}</a>
        @else
            <a href="/upcoming-charges?cancelled=1">{{ __('charges.upcoming.show_cancelled') }}</a>
        @endif
    </p>

    @if ($charges->isEmpty())
        <p class="muted">{{ __('charges.screens.none') }}</p>
    @else
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>{{ __('charges.fields.month') }}</th>
                    <th>{{ __('charges.fields.lease_contract') }}</th>
                    <th>{{ __('charges.fields.property') }}</th>
                    <th>{{ __('charges.fields.type') }}</th>
                    <th class="num">{{ __('charges.fields.amount') }}</th>
                    <th>{{ __('charges.fields.note') }}</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                @foreach ($charges as $charge)
                    @php($deposit = $charge->type->value === 'deposit' ? ($deposits[$charge->lease_contract_id.'-'.$charge->property_id] ?? null) : null)
                    <tr @class(['removed' => $charge->cancelled_at !== null])>
                        <td>{{ Month::label($charge->month) }}</td>
                        <td>№ {{ $charge->leaseContract?->number }} — {{ $charge->leaseContract?->tenant?->name }}</td>
                        <td>№ {{ $charge->property?->number }}</td>
                        <td>
                            {{ __('charges.types.'.$charge->type->value) }}
                            @if ($deposit)
                                <div class="muted">{{ __('charges.upcoming.deposit', [
                                    'contracted' => Format::money($deposit['contracted']),
                                    'entered' => Format::money($deposit['entered']),
                                    'remaining' => Format::money($deposit['remaining']),
                                ]) }}</div>
                            @endif
                        </td>
                        <td class="num">{{ Format::money($charge->amount) }}</td>
                        <td>
                            {{ $charge->note }}
                            @if ($charge->cancelled_at)
                                <div><strong>{{ __('charges.upcoming.cancelled') }}:</strong> {{ $charge->cancel_reason }}</div>
                            @endif
                        </td>
                        <td>
                            @if ($canManage && ! $charge->cancelled_at)
                                <a href="/upcoming-charges/{{ $charge->id }}/edit">{{ __('charges.screens.change') }}</a>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif
@endsection
