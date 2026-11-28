@extends('layouts.portal')
@use('App\Support\Format')
@use('App\Services\Charges\Month')

@section('title', $owner->name)

{{-- Порталът за собственика (27.11.2026). Само имотите по неговите договори; без битови сметки и без имена на служители; IBAN само „•••• 1234“. --}}
@section('content')
    <p><a href="/portal">{{ __('portal.back') }}</a></p>
    <h1>{{ $owner->name }}</h1>

    <h2>{{ __('portal.owner.contracts') }}</h2>
    @forelse ($contracts as $contract)
        @php($start = $portal->startsOn($contract))
        @php($end = $portal->endsOn($contract))
        @php($months = $portal->months($contract))
        @php($payee = $portal->payee($contract))
        <div class="card" data-contract="{{ $contract->id }}">
            <div style="display: flex; justify-content: space-between; gap: 8px; flex-wrap: wrap;">
                <strong>{{ __('portal.tenant.contract') }} № {{ $contract->number }}</strong>
                <span class="muted">{{ __('portal.tenant.states.'.$portal->state($contract)) }}</span>
            </div>
            <div>{{ __('contracts.types.'.$contract->type->value) }}</div>
            <div class="muted">
                {{ __('portal.tenant.period', ['from' => $start?->format('d.m.Y') ?? '—']) }}
                {{ $end ? __('portal.tenant.period_to', ['to' => $end->format('d.m.Y')]) : __('portal.tenant.no_end') }}
            </div>
            @if ($payee['method'])
                <div class="muted">{{ __('portal.owner.payee') }}: {{ implode(', ', array_filter([__('contracts.payment_methods.'.$payee['method']), $payee['name'], $payee['iban'] ? __('portal.owner.account').' '.$payee['iban'] : null])) }}</div>
            @endif

            @foreach ($portal->properties($contract) as $row)
                <div style="margin-top: 8px; padding-top: 8px; border-top: 1px solid var(--line, #e5e5e5);">
                    <div style="display: flex; justify-content: space-between; gap: 8px; flex-wrap: wrap;">
                        <span>№ {{ $row['property']->number }}, {{ \App\Support\Address::format($row['property']) }}</span>
                        @if ($row['rented'] !== null)
                            <strong>{{ $row['rented'] ? __('portal.owner.rented') : __('portal.owner.vacant') }}</strong>
                        @endif
                    </div>
                    @if ($row['tenant'])<div class="muted">{{ __('portal.owner.tenant') }}: {{ $row['tenant'] }}</div>@endif
                    @if ($row['rent'] !== null)<div class="muted">{{ __('portal.owner.rent') }}: {{ Format::money($row['rent']) }} €</div>@endif
                    @if ($row['commission'] !== null)<div class="muted">{{ __('portal.owner.commission') }}: {{ Format::percent($row['commission']) }}</div>@endif
                </div>
            @endforeach

            @if ($months['months'] === [])
                <p class="muted">{{ __('portal.owner.no_months') }}</p>
            @else
                <div style="overflow-x: auto; margin-top: 10px;">
                    <table style="width: 100%; border-collapse: collapse;">
                        <thead>
                            <tr>
                                <th style="text-align: left;">{{ __('portal.owner.month') }}</th>
                                <th style="text-align: right;">{{ __('portal.owner.accrued') }}</th>
                                <th style="text-align: right;">{{ __('portal.owner.paid') }}</th>
                                <th style="text-align: right;">{{ __('portal.owner.left') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($months['months'] as $month)
                                <tr>
                                    <td>{{ Month::label($month['month']) }}</td>
                                    <td style="text-align: right;">{{ Format::money($month['accrued']) }}</td>
                                    <td style="text-align: right;">{{ Format::money($month['paid']) }}</td>
                                    <td style="text-align: right;">{{ Format::money($month['left']) }}</td>
                                </tr>
                            @endforeach
                            <tr>
                                <th style="text-align: left;">{{ __('portal.owner.total') }}</th>
                                <th style="text-align: right;">{{ Format::money($months['accrued']) }}</th>
                                <th style="text-align: right;">{{ Format::money($months['paid']) }}</th>
                                <th></th>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div style="margin-top: 6px;">{{ __('portal.owner.left_total') }}: <strong>@include('portal.owes', ['amount' => $months['left']])</strong></div>
            @endif
        </div>
    @empty
        <p class="muted">{{ __('portal.owner.no_contracts') }}</p>
    @endforelse
@endsection
