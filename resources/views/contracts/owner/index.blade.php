@extends('layouts.app')

@use('App\Support\Format')

@section('title', __('registry.sections.owner-contracts'))
@section('width', 'list')

@section('content')
    @include('registry.partials.toolbar', ['section' => 'owner-contracts'])

    @if ($contracts->isEmpty())
        <p class="muted">{{ __('registry.empty') }}</p>
    @else
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th class="col-no">{{ __('contracts.screens.fields.number') }}</th>
                    <th>{{ __('contracts.screens.fields.owner') }}</th>
                    <th>{{ __('contracts.screens.fields.properties') }}</th>
                    <th>{{ __('contracts.screens.fields.type') }}</th>
                    <th>{{ __('contracts.screens.columns.signed_on') }}</th>
                    <th>{{ __('contracts.screens.columns.end_on') }}</th>
                    <th>{{ __('contracts.screens.fields.state') }}</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($contracts as $contract)
                    {{-- Имотите сега и предстоящите (без затворените). --}}
                    @php($current = $contract->lines->filter(fn ($line) => $line->valid_to === null || $line->valid_to->isFuture())->unique('property_id'))
                    <tr data-href="/owner-contracts/{{ $contract->id }}">
                        <td class="col-no"><a href="/owner-contracts/{{ $contract->id }}">{{ $contract->number }}</a></td>
                        <td>{{ $contract->owner?->name ?? __('contracts.screens.own_company') }}</td>
                        <td>{{ $current->map(fn ($line) => $line->property?->number)->filter()->sort()->implode(', ') ?: __('registry.none') }}</td>
                        <td>{{ __('contracts.types.'.$contract->type->value) }}</td>
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
