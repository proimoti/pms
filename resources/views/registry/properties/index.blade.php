@extends('layouts.app')

@section('title', __('registry.sections.properties'))
@section('width', 'list')

@section('content')
    @include('registry.partials.toolbar', ['section' => 'properties'])

    @if ($properties->isEmpty())
        <p class="muted">{{ __('registry.empty') }}</p>
    @else
        {{-- Колони и ред (т. 2.1, 24.11.2026): №, адрес, собственик и наемател към днес, отговорно лице; по номер. --}}
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th class="num">{{ __('registry.columns.number') }}</th>
                    <th>{{ __('registry.columns.address') }}</th>
                    <th>{{ __('registry.columns.owner') }}</th>
                    <th>{{ __('registry.columns.tenant') }}</th>
                    <th>{{ __('registry.columns.broker') }}</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($properties as $property)
                    @php($owner = $parties[$property->id]['owner'] ?? null)
                    @php($tenant = $parties[$property->id]['tenant'] ?? null)
                    <tr data-href="/properties/{{ $property->id }}">
                        <td class="num"><a href="/properties/{{ $property->id }}">{{ $property->number }}</a></td>
                        <td>{{ \App\Support\Address::format($property) }}</td>
                        <td>@if ($owner)<a href="/owners/{{ $owner->id }}">{{ $owner->name }}</a>@endif</td>
                        <td>@if ($tenant)<a href="/tenants/{{ $tenant->id }}">{{ $tenant->name }}</a>@endif</td>
                        <td>@if ($property->broker)<a href="/brokers/{{ $property->broker->id }}">{{ $property->broker->name }}</a>@endif</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        @include('registry.partials.pagination', ['paginator' => $properties])
    @endif
@endsection
