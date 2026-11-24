@extends('layouts.app')

@section('title', __('registry.sections.beneficiaries'))
@section('width', 'list')

@section('content')
    @include('registry.partials.toolbar', ['section' => 'beneficiaries'])

    @if ($beneficiaries->isEmpty())
        <p class="muted">{{ __('registry.empty') }}</p>
    @else
        {{-- IBAN не излиза в списъка (PROJECT.md, т. 2). Колони и ред (т. 2.4, 24.11.2026): №, име, договорите; по име. --}}
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th class="num">{{ __('registry.columns.number') }}</th>
                    <th>{{ __('registry.columns.name') }}</th>
                    <th>{{ __('registry.columns.owner_contracts') }}</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($beneficiaries as $beneficiary)
                    <tr data-href="/beneficiaries/{{ $beneficiary->id }}">
                        <td class="num"><a href="/beneficiaries/{{ $beneficiary->id }}">{{ $beneficiary->number }}</a></td>
                        <td>{{ $beneficiary->name }}</td>
                        <td>@include('registry.partials.contract-numbers', ['contracts' => $contracts[$beneficiary->id] ?? [], 'path' => 'owner-contracts'])</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        @include('registry.partials.pagination', ['paginator' => $beneficiaries])
    @endif
@endsection
