@extends('layouts.app')

@section('title', __('registry.sections.providers'))
@section('width', 'full')

@section('content')
    @include('registry.partials.toolbar', ['section' => 'providers'])

    @if ($providers->isEmpty())
        <p class="muted">{{ __('registry.empty') }}</p>
    @else
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>{{ __('utilities.fields.name') }}</th>
                    <th>{{ __('utilities.fields.easypay_name') }}</th>
                    <th>{{ __('utilities.fields.epay_name') }}</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($providers as $provider)
                    <tr>
                        <td><a href="/providers/{{ $provider->id }}">{{ $provider->name }}</a></td>
                        <td>{{ $provider->easypay_name ?? __('registry.none') }}</td>
                        <td>{{ $provider->epay_name ?? __('registry.none') }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        @include('registry.partials.pagination', ['paginator' => $providers])
    @endif
@endsection
