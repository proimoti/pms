@extends('layouts.app')

@use('App\Support\Format')
@use('App\Services\Charges\Month')

@php($heading = __('charges.screens.title').' — '.Month::label($month))
@section('title', $heading)
@section('width', 'full')

@section('content')
    <div class="row">
        <h1 style="margin: 0;">{{ $heading }}</h1>
        <div class="actions">
            <a href="/charges?month={{ $previous }}">‹ {{ Month::label($month->subMonth()) }}</a>
            <a href="/charges?month={{ $next }}">{{ Month::label($month->addMonth()) }} ›</a>
        </div>
    </div>

    @php($editable = $canManage && $lock === null)
    <p class="muted">
        @if ($lock)
            <strong>{{ __('charges.issue.issued_status', ['date' => $lock->locked_at->format('d.m.Y')]) }}</strong>
            {{ __('charges.issue.issued_by', ['user' => $lock->lockedBy?->name]) }}
        @else
            {{ __('charges.screens.status_working') }}
            @if ($stamp?->generated_at)
                {{ __('charges.screens.generated', ['date' => $stamp->generated_at->format('d.m.Y H:i'), 'user' => $stamp->generatedBy?->name]) }}
            @else
                {{ __('charges.screens.not_generated') }}
            @endif
        @endif
    </p>

    <div class="actions">
        @if ($editable)
            <form method="POST" action="/charges/generate">
                @csrf
                <input type="hidden" name="month" value="{{ $key }}">
                <button type="submit" title="{{ __('charges.screens.generate_hint') }}">
                    {{ $stamp?->generated_at ? __('charges.screens.generate_again') : __('charges.screens.generate') }}
                </button>
            </form>
            <a class="button secondary" href="/charges/create?month={{ $key }}">{{ __('charges.screens.manual_new') }}</a>
            @if ($stamp?->generated_at)
                <a class="button" href="/charges/issue?month={{ $key }}">{{ __('charges.issue.month') }}</a>
            @endif
        @endif
        @if ($canManage && ($lock || $statementsCount > 0))
            <a class="button secondary" href="/charges/corrections/create?month={{ $key }}">{{ __('charges.issue.manual_correction') }}</a>
        @endif
        @if ($statementsCount > 0)
            <a class="button secondary" href="/statements?month={{ $key }}">{{ __('charges.issue.statements_link') }} ({{ $statementsCount }})</a>
        @endif
        <a class="button secondary" href="/upcoming-charges">{{ __('charges.screens.upcoming_link') }}</a>
    </div>

    @if ($differences !== [])
        <h2>{{ __('charges.issue.differences') }}</h2>
        <p class="muted">{{ __('charges.issue.differences_intro') }}</p>
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>{{ __('charges.issue.account') }}</th>
                    <th>{{ __('charges.fields.contract') }}</th>
                    <th>{{ __('charges.fields.property') }}</th>
                    <th class="num">{{ __('charges.issue.booked') }}</th>
                    <th class="num">{{ __('charges.issue.target') }}</th>
                    <th class="num">{{ __('charges.issue.difference') }}</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                @foreach ($differences as $difference)
                    <tr>
                        <td>{{ __('charges.issue.accounts.'.$difference->account->value) }}</td>
                        <td>
                            @if ($difference->leaseContractId)
                                {{ __('registry.one.lease-contracts') }} № {{ $names['leases'][$difference->leaseContractId]?->number }} — {{ $names['leases'][$difference->leaseContractId]?->tenant?->name }}
                            @else
                                {{ __('registry.one.owner-contracts') }} № {{ $names['managements'][$difference->ownerContractId]?->number }} — {{ $names['managements'][$difference->ownerContractId]?->owner?->name }}
                            @endif
                        </td>
                        <td>№ {{ $names['properties'][$difference->propertyId] ?? '?' }}</td>
                        <td class="num">{{ Format::money($difference->booked) }}</td>
                        <td class="num">{{ Format::money($difference->target) }}</td>
                        <td class="num">{{ Format::money($difference->difference) }}</td>
                        <td>
                            @if ($canManage)
                                <form method="POST" action="/charges/corrections" class="row" style="justify-content: flex-start; gap: 6px;">
                                    @csrf
                                    <input type="hidden" name="month" value="{{ $key }}">
                                    <input type="hidden" name="key" value="{{ $difference->key }}">
                                    <input type="text" name="reason" placeholder="{{ __('charges.fields.reason') }}" aria-label="{{ __('charges.fields.reason') }}" maxlength="500" style="max-width: 220px;">
                                    <button type="submit" class="secondary" style="margin-top: 0;">{{ __('charges.issue.record_correction') }}</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif

    @if ($warnings !== [])
        <div class="warning" style="margin-top: 16px;">
            @foreach ($warnings as $warning)
                <div>{{ $warning->message }}</div>
            @endforeach
        </div>
    @endif

    <form method="GET" action="/charges" class="row" style="justify-content: flex-start; margin-top: 12px;">
        <input type="hidden" name="month" value="{{ $key }}">
        <input type="search" name="q" value="{{ $q }}" placeholder="{{ __('charges.screens.search') }}" aria-label="{{ __('charges.screens.search') }}" style="max-width: 320px;">
        <select name="filter" aria-label="{{ __('charges.screens.filter') }}" style="max-width: 240px;">
            @foreach (__('charges.screens.filters') as $value => $label)
                <option value="{{ $value }}" @selected($filter === $value)>{{ $label }}</option>
            @endforeach
        </select>
        <button type="submit" class="secondary" style="margin-top: 0;">{{ __('charges.screens.apply') }}</button>
    </form>

    <h2>{{ __('charges.screens.summary') }}</h2>
    <dl>
        <dt>{{ __('charges.screens.total_tenants') }}</dt><dd>{{ Format::money($totals['tenants']) }} €</dd>
        <dt>{{ __('charges.screens.total_owners') }}</dt><dd>{{ Format::money($totals['owners']) }} €</dd>
        <dt>{{ __('charges.screens.revenue') }}</dt>
        <dd @if (str_starts_with($totals['revenue'], '-')) style="color: var(--error);" @endif>{{ Format::money($totals['revenue']) }} €</dd>
        <dt>{{ __('charges.screens.contracts_count') }}</dt><dd>{{ $totals['contracts'] }}</dd>
    </dl>

    <h2>{{ __('charges.screens.to_tenants') }}</h2>
    @forelse ($tenantGroups as $rows)
        @php($lease = $rows->first()->leaseContract)
        <h3 style="margin: 18px 0 4px; font-size: 1rem;">
            <a href="/lease-contracts/{{ $lease->id }}">{{ __('registry.one.lease-contracts') }} № {{ $lease->number }}</a>
            — {{ $rows->first()->tenant?->name }}
        </h3>
        @include('charges.partials.rows', ['rows' => $rows, 'side' => 'tenant'])
    @empty
        <p class="muted">{{ __('charges.screens.none') }}</p>
    @endforelse

    <h2>{{ __('charges.screens.to_owners') }}</h2>
    @forelse ($ownerGroups as $rows)
        @php($management = $rows->first()->ownerContract)
        <h3 style="margin: 18px 0 4px; font-size: 1rem;">
            <a href="/owner-contracts/{{ $management->id }}">{{ __('registry.one.owner-contracts') }} № {{ $management->number }}</a>
            — {{ $rows->first()->owner?->name }}
        </h3>
        @include('charges.partials.rows', ['rows' => $rows, 'side' => 'owner'])
    @empty
        <p class="muted">{{ __('charges.screens.none') }}</p>
    @endforelse

    @if ($forDecision->isNotEmpty())
        <h2>{{ __('charges.screens.for_decision') }}</h2>
        <p class="muted">{{ __('charges.screens.for_decision_hint') }}</p>
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>{{ __('charges.fields.lease_contract') }}</th>
                    <th>{{ __('charges.fields.tenant') }}</th>
                    <th>{{ __('charges.fields.property') }}</th>
                    <th class="num">{{ __('charges.screens.days_without') }}</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($forDecision as $entry)
                    <tr>
                        <td><a href="/lease-contracts/{{ $entry['lease']?->id }}">№ {{ $entry['lease']?->number }}</a></td>
                        <td>{{ $entry['lease']?->tenant?->name }}</td>
                        <td><a href="/properties/{{ $entry['property']?->id }}">№ {{ $entry['property']?->number }}</a></td>
                        <td class="num">{{ $entry['item']->daysWithoutManagement }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif
@endsection
