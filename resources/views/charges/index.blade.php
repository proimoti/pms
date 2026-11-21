@extends('layouts.app')

@use('App\Support\Format')
@use('App\Services\Charges\Month')
@use('App\Services\Charges\Cents')

@php($heading = __('charges.screens.title').' — '.Month::label($month))
@section('title', $heading)
@section('width', 'board')

@section('content')
    @php($editable = $canManage && $lock === null)
    @php($opened = $q !== '' || $filter !== '')
    @php($monthEnd = $month->addMonth())
    @php($sum = fn ($rows) => Format::money(Cents::format($rows->whereNull('removed_at')->sum(fn ($row) => Cents::from($row->amount)))).' €')

    <div class="board-head">
        <div>
            <div class="eyebrow">
                @if ($lock)
                    {{ __('charges.issue.issued_status', ['date' => $lock->locked_at->format('d.m.Y')]) }}
                    {{ __('charges.issue.issued_by', ['user' => $lock->lockedBy?->name]) }}
                @else
                    {{ __('charges.screens.status_working') }}
                    @if ($stamp?->generated_at)
                        {{ __('charges.screens.generated', ['date' => $stamp->generated_at->format('d.m.Y H:i'), 'user' => $stamp->generatedBy?->name]) }}
                    @else
                        {{ __('charges.screens.not_generated') }}
                    @endif
                @endif
            </div>
            <div class="title-line">
                <h1>{{ __('charges.screens.title') }}</h1>
                <span class="month-switch">
                    <a href="/charges?month={{ $previous }}" aria-label="{{ __('charges.screens.previous_month') }}">‹</a>
                    <strong style="font-size: 1.2rem; padding: 0 6px;">{{ Month::label($month) }}</strong>
                    <a href="/charges?month={{ $next }}" aria-label="{{ __('charges.screens.next_month') }}">›</a>
                </span>
                @if ($lock)
                    <span class="badge issued">{{ __('charges.screens.state_issued') }}</span>
                @else
                    <span class="badge working">{{ __('charges.screens.state_working') }}</span>
                @endif
            </div>
        </div>
        <div class="actions">
            @if ($editable)
                <form method="POST" action="/charges/generate">
                    @csrf
                    <input type="hidden" name="month" value="{{ $key }}">
                    <button type="submit" class="secondary" title="{{ __('charges.screens.generate_hint') }}">
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
        </div>
    </div>

    <div class="metrics">
        <div class="metric"><div class="label">{{ __('charges.screens.total_tenants') }}</div><div class="value">{{ Format::money($totals['tenants']) }} €</div>
            @if ($breakdown->isNotEmpty())
                <div class="breakdown">@foreach ($breakdown as $type => $amount)<span>{{ __('charges.types_plural.'.$type) }} {{ Format::money($amount) }}</span>@endforeach</div>
            @endif
        </div>
        <div class="metric"><div class="label">{{ __('charges.screens.total_owners') }}</div><div class="value">{{ Format::money($totals['owners']) }} €</div></div>
        <div class="metric"><div class="label">{{ __('charges.screens.revenue') }}</div>
            <div @class(['value', 'negative' => str_starts_with($totals['revenue'], '-')])>{{ Format::money($totals['revenue']) }} €</div></div>
        <div class="metric"><div class="label">{{ __('charges.screens.contracts_count') }}</div><div class="value">{{ $totals['contracts'] }}</div></div>
    </div>

    @if ($warnings !== [])
        <div class="warning">
            @foreach ($warnings as $warning)
                @php($link = $warning->code === \App\Services\Charges\MonthWorksheet::FOR_DECISION ? '#for-decision'
                    : ($warning->leaseContractId !== null && $tenantGroups->has($warning->leaseContractId) ? '#lease-'.$warning->leaseContractId : null))
                <div>@if ($link)<a href="{{ $link }}">{{ $warning->message }}</a>@else{{ $warning->message }}@endif</div>
            @endforeach
        </div>
    @endif

    @if ($differences !== [])
        <div class="panel">
            <h2 style="margin-top: 0;">{{ __('charges.issue.differences') }}</h2>
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
        </div>
    @endif

    <form method="GET" action="/charges" class="toolbar">
        <input type="hidden" name="month" value="{{ $key }}">
        <input type="search" name="q" value="{{ $q }}" placeholder="{{ __('charges.screens.search') }}" aria-label="{{ __('charges.screens.search') }}">
        <select name="filter" aria-label="{{ __('charges.screens.filter') }}">
            @foreach (__('charges.screens.filters') as $value => $label)
                <option value="{{ $value }}" @selected($filter === $value)>{{ $label }}</option>
            @endforeach
        </select>
        <button type="submit" class="secondary">{{ __('charges.screens.apply') }}</button>
    </form>

    <h2>{{ __('charges.screens.to_tenants') }}</h2>
    @if ($tenantGroups->isEmpty())
        <p class="muted">{{ __('charges.screens.none') }}</p>
    @else
        <div class="accordion-list">
            @foreach ($tenantGroups as $rows)
                @php($lease = $rows->first()->leaseContract)
                @php($notes = $rows->flatMap(fn ($row) => $flagged[$row->id] ?? [])->unique())
                @php($terminated = $lease->terminated_on !== null && $lease->terminated_on->lt($monthEnd))
                @php($active = $rows->whereNull('removed_at'))
                @php($state = $notes->isNotEmpty() ? 'warning' : (($terminated || $active->isEmpty()) ? 'off' : 'ok'))
                <x-accordion :state="$state" :anchor="'lease-'.$lease->id" :open="$opened">
                    <x-slot:title>{{ $rows->first()->tenant?->name }}</x-slot:title>
                    <x-slot:sub>
                        {{ __('registry.one.lease-contracts') }} <strong>№ {{ $lease->number }}</strong>
                        · {{ __('charges.fields.property') }} {{ $rows->pluck('property.number')->unique()->map(fn ($n) => '№ '.$n)->implode(', ') }}
                        @if ($terminated) · {{ __('charges.screens.terminated_on', ['date' => Format::date($lease->terminated_on)]) }} @endif
                    </x-slot:sub>
                    @if ($notes->isNotEmpty())
                        <x-slot:note>{{ $notes->first() }}@if ($notes->count() > 1) (+{{ $notes->count() - 1 }})@endif</x-slot:note>
                    @endif
                    <x-slot:meta>{{ trans_choice('charges.screens.rows_count', $rows->count(), ['count' => $rows->count()]) }}</x-slot:meta>
                    <x-slot:amount>{{ $sum($rows) }}</x-slot:amount>

                    @include('charges.partials.rows', ['rows' => $rows, 'side' => 'tenant'])
                    <p class="muted" style="margin: 8px 0 0;"><a href="/lease-contracts/{{ $lease->id }}">{{ __('charges.screens.open_contract') }}</a></p>
                </x-accordion>
            @endforeach
        </div>
    @endif

    <h2>{{ __('charges.screens.to_owners') }}</h2>
    @if ($ownerGroups->isEmpty())
        <p class="muted">{{ __('charges.screens.none') }}</p>
    @else
        <div class="accordion-list">
            @foreach ($ownerGroups as $rows)
                @php($management = $rows->first()->ownerContract)
                @php($notes = $rows->flatMap(fn ($row) => $flagged[$row->id] ?? [])->unique())
                @php($active = $rows->whereNull('removed_at'))
                @php($state = $notes->isNotEmpty() ? 'warning' : ($active->isEmpty() ? 'off' : 'ok'))
                <x-accordion :state="$state" :anchor="'owner-'.$management->id" :open="$opened">
                    <x-slot:title>{{ $rows->first()->owner?->name }}</x-slot:title>
                    <x-slot:sub>
                        {{ __('registry.one.owner-contracts') }} <strong>№ {{ $management->number }}</strong>
                        · {{ __('contracts.types.'.$management->type->value) }}
                    </x-slot:sub>
                    @if ($notes->isNotEmpty())
                        <x-slot:note>{{ $notes->first() }}</x-slot:note>
                    @endif
                    <x-slot:meta>{{ trans_choice('charges.screens.rows_count', $rows->count(), ['count' => $rows->count()]) }}</x-slot:meta>
                    <x-slot:amount>{{ $sum($rows) }}</x-slot:amount>

                    @include('charges.partials.rows', ['rows' => $rows, 'side' => 'owner'])
                    <p class="muted" style="margin: 8px 0 0;"><a href="/owner-contracts/{{ $management->id }}">{{ __('charges.screens.open_contract') }}</a></p>
                </x-accordion>
            @endforeach
        </div>
    @endif

    @if ($forDecision->isNotEmpty())
        <div class="panel" id="for-decision">
            <h2 style="margin-top: 0;">{{ __('charges.screens.for_decision') }}</h2>
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
        </div>
    @endif

    <div class="panel" id="free-properties">
        <h2 style="margin-top: 0;">{{ __('charges.screens.free_properties') }}</h2>
        <p class="muted">{{ __('charges.screens.free_hint') }}</p>
        @if ($vacancies->isEmpty())
            <p class="muted">{{ __('charges.screens.free_none') }}</p>
        @else
            <div class="table-wrap">
                <table>
                    <thead>
                    <tr>
                        <th>{{ __('charges.fields.property') }}</th>
                        <th>{{ __('charges.fields.owner_contract') }}</th>
                        <th class="num">{{ __('charges.screens.free_days') }}</th>
                        <th class="num">{{ __('charges.screens.free_owner') }}</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($vacancies as $entry)
                        <tr>
                            <td><a href="/properties/{{ $entry['property']->id }}">№ {{ $entry['property']->number }}</a> {{ \App\Support\Address::format($entry['property']) }}</td>
                            <td>
                                <a href="/owner-contracts/{{ $entry['management']?->id }}"><strong>№ {{ $entry['management']?->number }}</strong></a>
                                {{ $entry['management']?->owner?->name }}
                                <div class="muted">{{ __('contracts.types.'.$entry['management']?->type->value) }}</div>
                            </td>
                            @php($item = $entry['item'])
                            <td class="num">
                                {{ $item->freeDays }}
                                <div class="muted">
                                    @if ($item->freeDays === $month->daysInMonth)
                                        {{ __('charges.screens.free_whole') }}
                                    @elseif ($item->lastDay === $month->daysInMonth - 1)
                                        {{ __('charges.screens.free_from', ['date' => $month->addDays($item->firstDay)->format('d.m')]) }}
                                    @else
                                        {{ $month->addDays($item->firstDay)->format('d.m') }}–{{ $month->addDays($item->lastDay)->format('d.m') }}
                                    @endif
                                </div>
                            </td>
                            <td class="num">{{ $entry['item']->ownerAmount === null ? '' : Format::money($entry['item']->ownerAmount).' €' }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
@endsection
