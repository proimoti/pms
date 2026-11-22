@extends('layouts.app')

@use('App\Support\Format')
@use('App\Services\Charges\Month')
@use('App\Services\Charges\Cents')

@php($heading = __('charges.issue.preview_title').' — '.Month::label($month))
@section('title', $heading)
@section('width', 'full')

@section('content')
    <h1>{{ $heading }}</h1>
    <p class="muted">{{ __('charges.issue.preview_intro') }}</p>

    @if ($warnings !== [])
        <div class="warning">
            @foreach ($warnings as $warning)
                <div>{{ $warning->message }}</div>
            @endforeach
        </div>
    @endif

    <dl>
        <dt>{{ __('charges.issue.statements_count') }}</dt><dd>{{ $tenants->count() }}</dd>
        <dt>{{ __('charges.screens.total_tenants') }}</dt><dd>{{ Format::money($totals['tenants']) }} €</dd>
        <dt>{{ __('charges.screens.total_owners') }}</dt><dd>{{ Format::money($totals['owners']) }} €</dd>
    </dl>

    <h2>{{ __('charges.screens.to_tenants') }}</h2>
    {{-- По един ред за всяко известие; разгъва се до редовете, както ще излязат в известието. --}}
    @if ($tenants->isEmpty())
        <p class="muted">{{ __('charges.screens.none') }}</p>
    @else
        <div class="accordion-list">
            @foreach ($tenants as $rows)
                <x-accordion :anchor="'notice-'.$rows->first()->lease_contract_id">
                    <x-slot:title>{{ $rows->first()->tenant?->name }}</x-slot:title>
                    <x-slot:sub>{{ __('registry.one.lease-contracts') }} <strong>№ {{ $rows->first()->leaseContract?->number }}</strong></x-slot:sub>
                    <x-slot:amount>{{ Format::money(Cents::format($rows->sum(fn ($row) => Cents::from($row->amount)))) }} €</x-slot:amount>

                    @include('charges.partials.notice-lines', ['rows' => $rows])
                </x-accordion>
            @endforeach
        </div>
    @endif

    @if ($issued->isNotEmpty())
        <h2>{{ __('charges.issue.already_issued') }}</h2>
        <ul>
            @foreach ($issued as $lease)
                <li>№ {{ $lease->number }} — {{ $lease->tenant?->name }}</li>
            @endforeach
        </ul>
    @endif

    @if ($forDecision !== [])
        <p class="muted">{{ __('charges.issue.not_issued_for_decision') }}</p>
    @endif

    <form method="POST" action="/charges/issue">
        @csrf
        <input type="hidden" name="month" value="{{ $key }}">
        <label><input type="checkbox" name="reviewed" value="1"> {{ __('charges.issue.reviewed') }}</label>
        <div class="actions">
            <button type="submit">{{ __('charges.issue.confirm') }}</button>
            <a href="/charges?month={{ $key }}" style="margin-top: 18px;">{{ __('registry.cancel') }}</a>
        </div>
    </form>
@endsection
