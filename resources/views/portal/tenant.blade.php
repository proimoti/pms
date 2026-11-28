@extends('layouts.portal')
@use('App\Support\Format')
@use('App\Services\Charges\Cents')

@section('title', $tenant->name)

{{-- Порталът за наемателя (27.11.2026). Без имена на служители и без сторнираните плащания и приключвания. --}}
@section('content')
    <p><a href="/portal">{{ __('portal.back') }}</a></p>
    <h1>{{ $tenant->name }}</h1>

    <div class="card">
        <div class="muted">{{ __('portal.tenant.owes_total') }}</div>
        <div style="font-size: 22px; font-weight: 600;">@include('portal.owes', ['amount' => $owes['total']])</div>
    </div>

    <h2>{{ __('portal.tenant.contracts') }}</h2>
    @forelse ($leases as $lease)
        @php($start = $portal->startsOn($lease))
        @php($end = $portal->endsOn($lease))
        <div class="card">
            <div style="display: flex; justify-content: space-between; gap: 8px; flex-wrap: wrap;">
                <strong>{{ __('portal.tenant.contract') }} № {{ $lease->number }}</strong>
                <span class="muted">{{ __('portal.tenant.states.'.$portal->state($lease)) }}</span>
            </div>
            <div class="muted">
                {{ __('portal.tenant.period', ['from' => $start?->format('d.m.Y') ?? '—']) }}
                {{ $end ? __('portal.tenant.period_to', ['to' => $end->format('d.m.Y')]) : __('portal.tenant.no_end') }}
            </div>
            @foreach ($portal->properties($lease) as $property)
                <div>№ {{ $property->number }}, {{ \App\Support\Address::format($property) }}</div>
            @endforeach
            <div style="margin-top: 6px;">{{ __('portal.tenant.owes') }}: <strong>@include('portal.owes', ['amount' => $owes['contracts'][$lease->id] ?? '0.00'])</strong></div>
        </div>
    @empty
        <p class="muted">{{ __('portal.tenant.no_contracts') }}</p>
    @endforelse

    <h2>{{ __('portal.tenant.bank.title') }}</h2>
    <div class="card">
        @if ($bank)
            <div>{{ $bank->bank_name }}</div>
            <div style="font-weight: 600; letter-spacing: 0.5px;">{{ trim(chunk_split((string) $bank->iban, 4, ' ')) }}</div>
            @if ($bank->bic)<div class="muted">BIC {{ $bank->bic }}</div>@endif
            @foreach ($leases->filter(fn ($l) => $portal->state($l) !== 'ended') as $lease)
                <div class="muted">{{ __('statements.pdf.reference_label') }}: {{ __('statements.pdf.reference', ['contract' => $lease->number]) }}</div>
            @endforeach
        @else
            <p class="muted" style="margin: 0;">{{ __('portal.tenant.bank.none') }}</p>
        @endif
    </div>

    <h2>{{ __('portal.tenant.statements') }}</h2>
    @forelse ($statements as $statement)
        <div class="card" style="display: flex; justify-content: space-between; gap: 8px; align-items: center; flex-wrap: wrap;">
            <div>
                <strong>№ {{ $statement->number }}</strong> · {{ \App\Services\Charges\Month::label($statement->month) }}
                <div class="muted">{{ __('portal.tenant.contract') }} № {{ $leaseNumbers[$statement->lease_contract_id] ?? '' }} · {{ Format::money($statement->total) }} €</div>
            </div>
            <a href="/portal/statements/{{ $statement->id }}/pdf">{{ __('portal.tenant.pdf') }}</a>
        </div>
    @empty
        <p class="muted">{{ __('portal.tenant.no_statements') }}</p>
    @endforelse

    <h2>{{ __('portal.tenant.payments') }}</h2>
    @forelse ($payments as $payment)
        <div class="card" style="display: flex; justify-content: space-between; gap: 8px; flex-wrap: wrap;">
            <div>{{ $payment->paid_on->format('d.m.Y') }} · {{ __('payments.methods.'.$payment->method) }}
                <div class="muted">{{ __('portal.tenant.contract') }} № {{ $leaseNumbers[$payment->lease_contract_id] ?? '' }}</div>
            </div>
            <strong>{{ Format::money($payment->amount) }} €</strong>
        </div>
    @empty
        <p class="muted">{{ __('portal.tenant.no_payments') }}</p>
    @endforelse

    @if ($deposits !== [])
        <h2>{{ __('portal.tenant.deposit.title') }}</h2>
        @foreach ($deposits as $leaseId => $deposit)
            <div class="card">
                <strong>{{ __('portal.tenant.contract') }} № {{ $leaseNumbers[$leaseId] ?? '' }}</strong>
                <div>{{ __('portal.tenant.deposit.contracted') }}: {{ Format::money(Cents::format($deposit['contracted'])) }} €</div>
                <div>{{ __('portal.tenant.deposit.paid') }}: {{ Format::money(Cents::format($deposit['paid'])) }} €</div>
                <div>{{ __('portal.tenant.deposit.held') }}: <strong>{{ Format::money(Cents::format($deposit['held'])) }} €</strong></div>
                @if ($deposit['closings']->isNotEmpty())
                    <div class="muted" style="margin-top: 8px;">{{ __('portal.tenant.deposit.closings') }}</div>
                    @foreach ($deposit['closings'] as $closing)
                        @php($sums = $closing->entries->groupBy(fn ($e) => $e->kind->value)->map(fn ($g) => -$g->sum(fn ($e) => Cents::from((string) $e->amount))))
                        @foreach (\App\Services\Payments\DepositClosingService::METHODS as $method => $kind)
                            @if (isset($sums[$kind->value]))
                                <div>{{ $closing->closed_on->format('d.m.Y') }} · {{ __('deposits.methods.'.$method) }}: {{ Format::money(Cents::format($sums[$kind->value])) }} €</div>
                            @endif
                        @endforeach
                    @endforeach
                @endif
            </div>
        @endforeach
    @endif
@endsection
