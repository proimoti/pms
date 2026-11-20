@extends('layouts.app')

@use('App\Support\Format')

@php($heading = __('registry.one.lease-contracts').' № '.$contract->number)
@php($basis = fn ($annexId) => $annexId === null ? __('contracts.screens.from_contract') : __('contracts.screens.annex', ['number' => $annexNumbers[$annexId] ?? '?']))
@section('title', $heading)
@section('width', 'full')

@section('content')
    <h1>{{ $heading }}</h1>

    @if ($withoutManagement !== [])
        <div class="warning">{{ __('contracts.screens.no_management_short', [
            'properties' => implode(', ', array_map(fn ($number) => __('contracts.screens.property_number', ['number' => $number]), $withoutManagement)),
        ]) }}</div>
    @endif

    <dl>
        <dt>{{ __('contracts.screens.fields.state') }}</dt><dd><strong>{{ __('contracts.screens.states.'.$contract->stateOn()) }}</strong></dd>
        <dt>{{ __('contracts.screens.fields.tenant') }}</dt>
        <dd>
            @if ($contract->tenant)
                <a href="/tenants/{{ $contract->tenant->id }}">№ {{ $contract->tenant->number }} {{ $contract->tenant->name }}</a>
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
                <th class="num">{{ __('contracts.screens.fields.rent') }}</th>
                <th class="num">{{ __('contracts.screens.fields.deposit') }}</th>
                <th class="num">{{ __('contracts.screens.fields.first_period_rent') }}</th>
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
                    <td class="num">{{ Format::money($line->rent) }}</td>
                    <td class="num">{{ Format::money($line->deposit) }}</td>
                    <td class="num">{{ $line->first_period_rent === null ? '' : Format::money($line->first_period_rent) }}</td>
                    <td>{{ $basis($line->annex_id) }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>

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
            <a class="button" style="margin-top: 0;" href="/lease-contracts/{{ $contract->id }}/annexes/create">{{ __('contracts.screens.new_annex') }}</a>
            <a class="button secondary" style="margin-top: 0;" href="/lease-contracts/{{ $contract->id }}/correct">{{ __('contracts.screens.correction') }}</a>
        </div>
    @endif
    <p class="muted">{{ __('contracts.screens.hints.lease_amount_fields') }}</p>

    @if ($canIssue)
        <form method="GET" action="/lease-contracts/{{ $contract->id }}/issue" class="row" style="justify-content: flex-start; margin-top: 8px;">
            <input type="text" inputmode="numeric" maxlength="7" name="month" placeholder="мм.гггг" aria-label="{{ __('charges.issue.now_month') }}" style="max-width: 140px;">
            <button type="submit" class="secondary" style="margin-top: 0;">{{ __('charges.issue.now') }}</button>
        </form>
    @endif

    <h2>{{ __('charges.issue.ledger') }}</h2>
    @if ($entries->isEmpty())
        <p class="muted">{{ __('charges.issue.no_entries') }}</p>
    @else
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>{{ __('charges.fields.month') }}</th>
                    <th>{{ __('charges.issue.entry_date') }}</th>
                    <th>{{ __('charges.issue.account') }}</th>
                    <th>{{ __('charges.fields.type') }}</th>
                    <th class="num">{{ __('charges.fields.amount') }}</th>
                    <th>{{ __('charges.fields.source') }}</th>
                    <th>{{ __('charges.fields.reason') }}</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($entries as $entry)
                    <tr>
                        <td>{{ \App\Services\Charges\Month::label($entry->month) }}</td>
                        <td>{{ Format::date($entry->entry_date) }}</td>
                        <td>{{ __('charges.issue.accounts.'.$entry->account->value) }}</td>
                        <td>{{ __('charges.types.'.$entry->type->value) }}</td>
                        <td class="num">{{ Format::money($entry->amount) }}</td>
                        <td>{{ __('charges.issue.kinds.'.$entry->kind->value) }}</td>
                        <td>{{ $entry->reason }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif

    @include('registry.partials.record-actions', ['section' => 'lease-contracts', 'record' => $contract])
@endsection
