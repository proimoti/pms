@extends('layouts.app')

@section('title', __('registry.sections.brokers'))
@section('width', 'list')

@section('content')
    @include('registry.partials.toolbar', ['section' => 'brokers'])

    @if ($brokers->isEmpty())
        <p class="muted">{{ __('registry.empty') }}</p>
    @else
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>{{ __('registry.fields.name') }}</th>
                    <th>{{ __('registry.fields.phone') }}</th>
                    <th>{{ __('registry.fields.email') }}</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($brokers as $broker)
                    <tr data-href="/brokers/{{ $broker->id }}">
                        <td><a href="/brokers/{{ $broker->id }}">{{ $broker->name }}</a></td>
                        <td>{{ $broker->phone }}</td>
                        <td>{{ $broker->email }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        @include('registry.partials.pagination', ['paginator' => $brokers])
    @endif
@endsection
