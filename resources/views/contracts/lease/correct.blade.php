@extends('layouts.app')

@use('App\Support\Format')

@php($heading = __('contracts.screens.correction').' — '.__('registry.one.lease-contracts').' № '.$contract->number)
@php($basis = fn ($annexId) => $annexId === null ? __('contracts.screens.from_contract') : __('contracts.screens.annex', ['number' => $annexNumbers[$annexId] ?? '?']))
@section('title', $heading)
@section('width', 'full')

@section('content')
    <h1>{{ $heading }}</h1>
    {{-- PROJECT.md, „Поправки“: на място, само докато няма начисления; после само с анекс. --}}
    <div class="warning">{{ __('contracts.screens.hints.correction') }}</div>

    <form method="POST" action="/lease-contracts/{{ $contract->id }}/correct">
        @csrf
        @method('PUT')

        <label for="tenant_id">{{ __('contracts.screens.fields.tenant') }} *</label>
        <select id="tenant_id" name="tenant_id" style="max-width: 520px;">
            @unless ($tenants->contains('id', $contract->tenant_id))
                <option value="{{ $contract->tenant_id }}" selected>№ {{ $contract->tenant?->number }} {{ $contract->tenant?->name }}</option>
            @endunless
            @foreach ($tenants as $tenant)
                <option value="{{ $tenant->id }}" @selected((string) old('tenant_id', $contract->tenant_id) === (string) $tenant->id)>
                    № {{ $tenant->number }} {{ $tenant->name }} ({{ __('registry.kinds.'.$tenant->kind->value) }})
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
                    <th>{{ __('contracts.screens.fields.rent') }}</th>
                    <th>{{ __('contracts.screens.fields.deposit') }}</th>
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
                        <td><input type="text" inputmode="numeric" maxlength="10" autocomplete="off" placeholder="{{ __('registry.date_placeholder') }}" name="lines[{{ $line->id }}][valid_from]" value="{{ \App\Support\BgDate::toInput(old("lines.{$line->id}.valid_from", $line->valid_from)) }}" aria-label="{{ __('contracts.screens.fields.from') }}"></td>
                        <td>
                            @if ($line->closed_by_annex_id)
                                <input type="text" inputmode="numeric" maxlength="10" autocomplete="off" placeholder="{{ __('registry.date_placeholder') }}" name="lines[{{ $line->id }}][valid_to]" value="{{ \App\Support\BgDate::toInput(old("lines.{$line->id}.valid_to", $line->valid_to)) }}" aria-label="{{ __('contracts.screens.fields.to') }}">
                            @else
                                {{ Format::date($line->valid_to) }}
                            @endif
                        </td>
                        <td><input type="text" inputmode="decimal" name="lines[{{ $line->id }}][rent]" value="{{ old("lines.{$line->id}.rent", $line->rent) }}" aria-label="{{ __('contracts.screens.fields.rent') }}"></td>
                        <td><input type="text" inputmode="decimal" name="lines[{{ $line->id }}][deposit]" value="{{ old("lines.{$line->id}.deposit", $line->deposit) }}" aria-label="{{ __('contracts.screens.fields.deposit') }}"></td>
                        <td>{{ $basis($line->annex_id) }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        <p class="muted">{{ __('contracts.screens.hints.correction_dates_lease') }}</p>

        <div class="actions">
            <button type="submit">{{ __('registry.save') }}</button>
            <a href="/lease-contracts/{{ $contract->id }}" style="margin-top: 18px;">{{ __('registry.cancel') }}</a>
        </div>
    </form>
@endsection
