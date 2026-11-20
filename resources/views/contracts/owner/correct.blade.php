@extends('layouts.app')

@use('App\Support\Format')
@use('App\Enums\CalculationMethod')
@use('App\Enums\OwnerContractType')

@php($heading = __('contracts.screens.correction').' — '.__('registry.one.owner-contracts').' № '.$contract->number)
@php($basis = fn ($annexId) => $annexId === null ? __('contracts.screens.from_contract') : __('contracts.screens.annex', ['number' => $annexNumbers[$annexId] ?? '?']))
@section('title', $heading)
@section('width', 'full')

@section('content')
    <h1>{{ $heading }}</h1>
    {{-- PROJECT.md, „Поправки“: на място, само докато няма начисления; после само с анекс. --}}
    <div class="warning">{{ __('contracts.screens.hints.correction') }}</div>

    <form method="POST" action="/owner-contracts/{{ $contract->id }}/correct">
        @csrf
        @method('PUT')

        <label for="owner_id">{{ __('contracts.screens.fields.owner') }}@unless ($contract->type === OwnerContractType::Own) *@endunless</label>
        <select id="owner_id" name="owner_id" style="max-width: 520px;">
            @if ($contract->type === OwnerContractType::Own)
                <option value="">{{ __('contracts.screens.own_company') }}</option>
            @endif
            @foreach ($owners as $owner)
                <option value="{{ $owner->id }}" @selected((string) old('owner_id', $contract->owner_id) === (string) $owner->id)>
                    № {{ $owner->number }} {{ $owner->name }} ({{ __('registry.kinds.'.$owner->kind->value) }})
                </option>
            @endforeach
        </select>

        <h2>{{ __('contracts.screens.sections.lines') }}</h2>
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>{{ __('contracts.screens.fields.property') }}</th>
                    <th>{{ __('contracts.screens.fields.from') }}</th>
                    <th>{{ __('contracts.screens.fields.to') }}</th>
                    @if ($contract->calculation === CalculationMethod::Fixed)
                        <th>{{ __('contracts.screens.fields.fixed_amount') }}</th>
                    @endif
                    <th>{{ __('contracts.screens.fields.basis') }}</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($contract->lines as $line)
                    <tr>
                        <td>
                            <select name="lines[{{ $line->id }}][property_id]" aria-label="{{ __('contracts.screens.fields.property') }}">
                                {{-- Сегашният имот е в списъка, дори да е архивиран или неприкачен. --}}
                                @unless ($properties->contains('id', $line->property_id))
                                    <option value="{{ $line->property_id }}" selected>№ {{ $line->property?->number }}</option>
                                @endunless
                                @foreach ($properties as $property)
                                    <option value="{{ $property->id }}" @selected((string) old("lines.{$line->id}.property_id", $line->property_id) === (string) $property->id)>
                                        № {{ $property->number }} {{ \App\Support\Address::format($property) }}
                                    </option>
                                @endforeach
                            </select>
                        </td>
                        <td><input type="date" name="lines[{{ $line->id }}][valid_from]" value="{{ old("lines.{$line->id}.valid_from", $line->valid_from->toDateString()) }}" aria-label="{{ __('contracts.screens.fields.from') }}"></td>
                        <td>
                            @if ($line->closed_by_annex_id)
                                <input type="date" name="lines[{{ $line->id }}][valid_to]" value="{{ old("lines.{$line->id}.valid_to", $line->valid_to?->toDateString()) }}" aria-label="{{ __('contracts.screens.fields.to') }}">
                            @else
                                {{ Format::date($line->valid_to) }}
                            @endif
                        </td>
                        @if ($contract->calculation === CalculationMethod::Fixed)
                            <td><input type="text" inputmode="decimal" name="lines[{{ $line->id }}][fixed_amount]" value="{{ old("lines.{$line->id}.fixed_amount", $line->fixed_amount) }}" aria-label="{{ __('contracts.screens.fields.fixed_amount') }}"></td>
                        @endif
                        <td>{{ $basis($line->annex_id) }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        <p class="muted">{{ __('contracts.screens.hints.correction_dates') }}</p>

        @if ($contract->commissions->isNotEmpty())
            <h2>{{ __('contracts.screens.sections.commissions') }}</h2>
            <div class="table-wrap">
                <table>
                    <thead>
                    <tr>
                        <th>{{ __('contracts.screens.fields.commission_percent') }}</th>
                        <th>{{ __('contracts.screens.fields.from') }}</th>
                        <th>{{ __('contracts.screens.fields.to') }}</th>
                        <th>{{ __('contracts.screens.fields.basis') }}</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($contract->commissions as $commission)
                        <tr>
                            <td><input type="text" inputmode="decimal" name="commissions[{{ $commission->id }}]" value="{{ old("commissions.{$commission->id}", $commission->percent) }}" aria-label="{{ __('contracts.screens.fields.commission_percent') }}" style="max-width: 140px;"></td>
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
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($contract->payees as $payee)
                        {{-- „Самият собственик“ = празно: при смяна на собственика минава към новия. --}}
                        @php($current = $payee->beneficiary?->owner_id === $contract->owner_id ? '' : (string) $payee->beneficiary_id)
                        <tr>
                            <td>
                                <select name="payees[{{ $payee->id }}][beneficiary_id]" aria-label="{{ __('contracts.screens.fields.beneficiary') }}">
                                    <option value="">{{ __('registry.owner_himself') }}</option>
                                    @foreach ($beneficiaries as $beneficiary)
                                        @continue($beneficiary->owner_id !== null && $beneficiary->owner_id === $contract->owner_id)
                                        <option value="{{ $beneficiary->id }}" @selected((string) old("payees.{$payee->id}.beneficiary_id", $current) === (string) $beneficiary->id)>{{ $beneficiary->name }}</option>
                                    @endforeach
                                </select>
                            </td>
                            <td>
                                <select name="payees[{{ $payee->id }}][payment_method]" aria-label="{{ __('contracts.screens.fields.payment_method') }}">
                                    @foreach ($methods as $method)
                                        <option value="{{ $method->value }}" @selected(old("payees.{$payee->id}.payment_method", $payee->payment_method->value) === $method->value)>{{ __('contracts.payment_methods.'.$method->value) }}</option>
                                    @endforeach
                                </select>
                            </td>
                            <td>{{ Format::date($payee->valid_from) }}</td>
                            <td>{{ Format::date($payee->valid_to) }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        <div class="actions">
            <button type="submit">{{ __('registry.save') }}</button>
            <a href="/owner-contracts/{{ $contract->id }}" style="margin-top: 18px;">{{ __('registry.cancel') }}</a>
        </div>
    </form>
@endsection
