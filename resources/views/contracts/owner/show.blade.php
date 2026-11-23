@extends('layouts.app')

@use('App\Support\Format')
@use('App\Enums\CalculationMethod')

@php($heading = __('registry.one.owner-contracts').' № '.$contract->number)
@php($basis = fn ($annexId) => $annexId === null ? __('contracts.screens.from_contract') : __('contracts.screens.annex', ['number' => $annexNumbers[$annexId] ?? '?']))
@section('title', $heading)
@section('width', 'full')

@section('content')
    <h1>{{ $heading }}</h1>

    <dl>
        <dt>{{ __('contracts.screens.fields.state') }}</dt><dd><strong>{{ __('contracts.screens.states.'.$contract->stateOn()) }}</strong></dd>
        <dt>{{ __('contracts.screens.fields.type') }}</dt><dd>{{ __('contracts.types.'.$contract->type->value) }}</dd>
        <dt>{{ __('contracts.screens.fields.owner') }}</dt>
        <dd>
            @if ($contract->owner)
                <a href="/owners/{{ $contract->owner->id }}">№ {{ $contract->owner->number }} {{ $contract->owner->name }}</a>
            @else
                {{ __('contracts.screens.own_company') }}
            @endif
        </dd>
        <dt>{{ __('contracts.screens.fields.signed_on') }}</dt><dd>{{ Format::date($contract->signed_on) }}</dd>
        <dt>{{ __('contracts.screens.fields.end_on') }}</dt><dd>{{ Format::date($contract->end_on) }}</dd>
        <dt>{{ __('contracts.screens.fields.terminated_on') }}</dt><dd>{{ Format::date($contract->terminated_on) }}</dd>
        <dt>{{ __('contracts.screens.fields.note') }}</dt><dd style="white-space: pre-line;">{{ $contract->note ?? __('registry.none') }}</dd>
    </dl>

    <h2>{{ __('contracts.screens.sections.lines') }}</h2>
    <div class="table-wrap">
        <table>
            <thead>
            <tr>
                <th>{{ __('contracts.screens.fields.property') }}</th>
                <th>{{ __('contracts.screens.fields.from') }}</th>
                <th>{{ __('contracts.screens.fields.to') }}</th>
                @if ($contract->calculation === CalculationMethod::Fixed)
                    <th class="num">{{ __('contracts.screens.fields.fixed_amount') }}</th>
                @endif
                <th>{{ __('contracts.screens.fields.basis') }}</th>
            </tr>
            </thead>
            <tbody>
            @foreach ($contract->lines as $line)
                <tr>
                    <td>№ {{ $line->property?->number }} {{ $line->property ? \App\Support\Address::format($line->property) : '' }}</td>
                    <td>{{ Format::date($line->valid_from) }}</td>
                    <td>
                        {{ Format::date($line->valid_to) }}
                        @if ($line->closed_by_annex_id)
                            <span class="muted">({{ __('contracts.screens.removed_by', ['number' => $annexNumbers[$line->closed_by_annex_id] ?? '?']) }})</span>
                        @endif
                    </td>
                    @if ($contract->calculation === CalculationMethod::Fixed)
                        <td class="num">{{ Format::money($line->fixed_amount) }}</td>
                    @endif
                    <td>{{ $basis($line->annex_id) }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>

    @if ($contract->calculation === CalculationMethod::Percent)
        <h2>{{ __('contracts.screens.sections.commissions') }}</h2>
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th class="num">{{ __('contracts.screens.fields.commission_percent') }}</th>
                    <th>{{ __('contracts.screens.fields.from') }}</th>
                    <th>{{ __('contracts.screens.fields.to') }}</th>
                    <th>{{ __('contracts.screens.fields.basis') }}</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($contract->commissions as $commission)
                    <tr>
                        <td class="num">{{ Format::percent($commission->percent) }}</td>
                        <td>{{ Format::date($commission->valid_from) }}</td>
                        <td>{{ Format::date($commission->valid_to) }}</td>
                        <td>{{ $basis($commission->annex_id) }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif

    @if ($contract->payees->isNotEmpty())
        <h2>{{ __('contracts.screens.sections.payees') }}</h2>
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>{{ __('contracts.screens.fields.beneficiary') }}</th>
                    <th>{{ __('contracts.screens.fields.payment_method') }}</th>
                    <th>{{ __('contracts.screens.fields.from') }}</th>
                    <th>{{ __('contracts.screens.fields.to') }}</th>
                    <th>{{ __('contracts.screens.fields.basis') }}</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($contract->payees as $payee)
                    <tr>
                        <td>
                            @if ($payee->beneficiary)
                                <a href="/beneficiaries/{{ $payee->beneficiary->id }}">{{ $payee->beneficiary->name }}</a>
                                @if ($payee->beneficiary->owner_id)
                                    <span class="muted">({{ __('registry.owner_himself') }})</span>
                                @endif
                            @endif
                        </td>
                        <td>{{ __('contracts.payment_methods.'.$payee->payment_method->value) }}</td>
                        <td>{{ Format::date($payee->valid_from) }}</td>
                        <td>{{ Format::date($payee->valid_to) }}</td>
                        <td>{{ $basis($payee->annex_id) }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif

    <h2>{{ __('contracts.screens.sections.annexes') }}</h2>
    @if ($contract->annexes->isEmpty())
        <p class="muted">{{ __('contracts.screens.no_annexes') }}</p>
    @else
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>{{ __('contracts.screens.fields.number') }}</th>
                    <th>{{ __('contracts.screens.fields.annex_signed_on') }}</th>
                    <th>{{ __('contracts.screens.fields.note') }}</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($contract->annexes as $annex)
                    <tr>
                        <td>{{ __('contracts.screens.annex', ['number' => $annex->number]) }}</td>
                        <td>{{ Format::date($annex->signed_on) }}</td>
                        <td style="white-space: pre-line;">{{ $annex->note ?? __('registry.none') }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif

    @if ($contract->extensions->isNotEmpty())
        <h2>{{ __('contracts.screens.sections.extensions') }}</h2>
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>{{ __('contracts.screens.fields.previous_end_on') }}</th>
                    <th>{{ __('contracts.screens.fields.new_end_on') }}</th>
                    <th>{{ __('contracts.screens.fields.basis') }}</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($contract->extensions as $extension)
                    <tr>
                        <td>{{ Format::date($extension->previous_end_on) }}</td>
                        <td>{{ Format::date($extension->end_on) }}</td>
                        <td>{{ $basis($extension->annex_id) }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif

    @if (auth()->user()->access_level === \App\Enums\AccessLevel::Write && ! $contract->isArchived())
        <div class="actions" style="margin-top: 18px;">
            <a class="button" style="margin-top: 0;" href="/owner-contracts/{{ $contract->id }}/annexes/create">{{ __('contracts.screens.new_annex') }}</a>
            <a class="button secondary" style="margin-top: 0;" href="/owner-contracts/{{ $contract->id }}/correct">{{ __('contracts.screens.correction') }}</a>
        </div>
    @endif
    <p class="muted">{{ __('contracts.screens.hints.amount_fields') }}</p>

    {{-- Досие (23.11.2026): записите по 408 към собственика. --}}
    <h2>{{ __('dossiers.sections.ledger_408') }}</h2>
    @if ($ownerEntries->isEmpty())
        <p class="muted">{{ __('charges.issue.no_entries') }}</p>
    @else
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>{{ __('charges.fields.month') }}</th>
                    <th>{{ __('charges.issue.entry_date') }}</th>
                    <th>{{ __('charges.fields.property') }}</th>
                    <th class="num">{{ __('charges.fields.amount') }}</th>
                    <th>{{ __('charges.fields.source') }}</th>
                    <th>{{ __('charges.fields.reason') }}</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($ownerEntries as $entry)
                    <tr>
                        <td>{{ \App\Services\Charges\Month::label($entry->month) }}</td>
                        <td>{{ \App\Support\Format::date($entry->entry_date) }}</td>
                        <td>@if ($entry->property)<a href="/properties/{{ $entry->property_id }}">№ {{ $entry->property->number }}</a>@endif</td>
                        <td class="num">{{ \App\Support\Format::money($entry->amount) }}</td>
                        <td>{{ __('charges.issue.kinds.'.$entry->kind->value) }}</td>
                        <td>{{ $entry->reason }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif

    @include('registry.partials.record-actions', ['section' => 'owner-contracts', 'record' => $contract])
@endsection
