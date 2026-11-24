@extends('layouts.app')

@use('App\Support\Format')

@section('title', __('registry.sections.lease-contracts'))
@section('width', 'list')

@section('content')
    @include('registry.partials.toolbar', ['section' => 'lease-contracts'])

    @if ($contracts->isEmpty())
        <p class="muted">{{ __('registry.empty') }}</p>
    @else
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th class="num">{{ __('contracts.screens.fields.number') }}</th>
                    <th>{{ __('contracts.screens.fields.tenant') }}</th>
                    <th>{{ __('contracts.screens.fields.properties') }}</th>
                    <th class="num">{{ __('contracts.screens.columns.rent') }}</th>
                    <th>{{ __('contracts.screens.columns.signed_on') }}</th>
                    <th>{{ __('contracts.screens.columns.end_on') }}</th>
                    <th>{{ __('contracts.screens.fields.state') }}</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($contracts as $contract)
                    {{-- Имотите сега и предстоящите (без затворените); наемът е сборът на сегашните варианти. --}}
                    @php($current = $contract->lines->filter(fn ($line) => $line->valid_to === null || $line->valid_to->isFuture()))
                    @php($latest = $current->groupBy('property_id')->map(fn ($versions) => $versions->sortBy('valid_from')->last()))
                    <tr data-href="/lease-contracts/{{ $contract->id }}">
                        <td class="num"><a href="/lease-contracts/{{ $contract->id }}">{{ $contract->number }}</a></td>
                        <td>{{ $contract->tenant?->name }}</td>
                        <td>{{ $latest->map(fn ($line) => $line->property?->number)->filter()->sort()->implode(', ') ?: __('registry.none') }}</td>
                        <td class="num">{{ $latest->isEmpty() ? __('registry.none') : Format::money(number_format($latest->sum(fn ($line) => (float) $line->rent), 2, '.', '')) }}</td>
                        <td>{{ Format::date($contract->signed_on) }}</td>
                        <td>{{ Format::date($contract->end_on) }}</td>
                        @php($state = $contract->stateOn())
                        <td><span class="badge state-{{ $state }}">{{ __('contracts.screens.states.'.$state) }}</span></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        @include('registry.partials.pagination', ['paginator' => $contracts])
    @endif
@endsection
