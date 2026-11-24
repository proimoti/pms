@extends('layouts.app')

@section('title', __('registry.sections.'.$section))
@section('width', 'list')

@section('content')
    @include('registry.partials.toolbar', ['section' => $section])

    @if ($parties->isEmpty())
        <p class="muted">{{ __('registry.empty') }}</p>
    @else
        {{-- Колони и ред (т. 2.3 и 2.5, 24.11.2026): №, име, договорите (на наемателя — действащите); по име. --}}
        @php($owners = $section === 'owners')
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th class="num">{{ __('registry.columns.number') }}</th>
                    <th>{{ __('registry.columns.name') }}</th>
                    <th>{{ __('registry.columns.'.($owners ? 'owner_contracts' : 'lease_contracts')) }}</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($parties as $party)
                    <tr data-href="/{{ $section }}/{{ $party->id }}">
                        <td class="num"><a href="/{{ $section }}/{{ $party->id }}">{{ $party->number }}</a></td>
                        <td>{{ $party->name }}</td>
                        <td>@include('registry.partials.contract-numbers', ['contracts' => $contracts[$party->id] ?? [], 'path' => $owners ? 'owner-contracts' : 'lease-contracts'])</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        @include('registry.partials.pagination', ['paginator' => $parties])
    @endif
@endsection
