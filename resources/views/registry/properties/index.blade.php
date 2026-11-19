@extends('layouts.app')

@section('title', __('registry.sections.properties'))
@section('width', 'full')

@section('content')
    @include('registry.partials.toolbar', ['section' => 'properties'])

    @if ($properties->isEmpty())
        <p class="muted">{{ __('registry.empty') }}</p>
    @else
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th class="num">{{ __('registry.fields.number') }}</th>
                    <th>{{ __('registry.fields.property_type_id') }}</th>
                    <th>{{ __('registry.fields.address') }}</th>
                    <th class="num">{{ __('registry.fields.area') }}</th>
                    <th>{{ __('registry.fields.broker_id') }}</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($properties as $property)
                    <tr>
                        <td class="num"><a href="/properties/{{ $property->id }}">{{ $property->number }}</a></td>
                        <td>{{ $property->propertyType?->name }}</td>
                        <td><a href="/properties/{{ $property->id }}">{{ \App\Support\Address::format($property) }}</a></td>
                        <td class="num">{{ $property->area !== null ? number_format((float) $property->area, 2, ',', ' ') : '' }}</td>
                        <td>{{ $property->broker?->name }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        @include('registry.partials.pagination', ['paginator' => $properties])
    @endif
@endsection
