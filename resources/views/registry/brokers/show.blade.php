@extends('layouts.app')

@section('title', $broker->name)
@section('width', 'wide')

@section('content')
    <h1>{{ __('registry.one.brokers') }} № {{ $broker->number }}: {{ $broker->name }}</h1>

    <dl>
        <dt>{{ __('registry.fields.phone') }}</dt><dd>{{ $broker->phone ?? __('registry.none') }}</dd>
        <dt>{{ __('registry.fields.email') }}</dt><dd>{{ $broker->email ?? __('registry.none') }}</dd>
        <dt>{{ __('registry.fields.note') }}</dt><dd style="white-space: pre-line;">{{ $broker->note ?? __('registry.none') }}</dd>
    </dl>

    {{-- Имотите на лицето (т. 2.8, 24.11.2026): № имот, адрес, собственик; по собственик. --}}
    <h2 id="broker-properties">{{ __('dossiers.sections.properties') }}</h2>
    @if ($properties->isEmpty())
        <p class="muted">{{ __('dossiers.none') }}</p>
    @else
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th class="num">{{ __('registry.columns.number') }}</th>
                    <th>{{ __('registry.columns.address') }}</th>
                    <th>{{ __('registry.columns.owner') }}</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($properties as $property)
                    @php($owner = $parties[$property->id]['owner'] ?? null)
                    <tr data-href="/properties/{{ $property->id }}">
                        <td class="num"><a href="/properties/{{ $property->id }}">{{ $property->number }}</a></td>
                        <td>{{ \App\Support\Address::format($property) }}</td>
                        <td>@if ($owner)<a href="/owners/{{ $owner->id }}">{{ $owner->name }}</a>@endif</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif

    @include('registry.partials.record-actions', ['section' => 'brokers', 'record' => $broker])
@endsection
