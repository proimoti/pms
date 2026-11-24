@extends('layouts.app')

@section('title', __('registry.sections.brokers'))
@section('width', 'list')

@section('content')
    @include('registry.partials.toolbar', ['section' => 'brokers'])

    @if ($brokers->isEmpty())
        <p class="muted">{{ __('registry.empty') }}</p>
    @else
        {{-- Колони и ред (т. 2.7, 24.11.2026): №, име, телефон, имейл; по име. --}}
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th class="col-no">{{ __('registry.columns.number') }}</th>
                    <th>{{ __('registry.columns.name') }}</th>
                    <th>{{ __('registry.columns.phone') }}</th>
                    <th>{{ __('registry.columns.email') }}</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($brokers as $broker)
                    <tr data-href="/brokers/{{ $broker->id }}">
                        <td class="col-no"><a href="/brokers/{{ $broker->id }}">{{ $broker->number }}</a></td>
                        <td>{{ $broker->name }}</td>
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
