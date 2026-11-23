@extends('layouts.app')

@use('App\Support\Format')
@use('App\Services\Charges\Month')
@use('App\Services\Charges\Cents')

@php($heading = __('charges.issue.now_title').' — '.__('registry.one.lease-contracts').' № '.$contract->number)
@section('title', $heading)
@section('width', 'wide')

@section('content')
    <h1>{{ $heading }}</h1>
    <p class="muted">{{ __('charges.issue.now_intro') }}</p>
    <p>{{ $contract->tenant?->name }}</p>

    <form method="GET" action="/lease-contracts/{{ $contract->id }}/issue" class="row" style="justify-content: flex-start;">
        <label for="month" style="margin: 0;">{{ __('charges.issue.now_month') }}</label>
        <input id="month" type="text" inputmode="numeric" maxlength="7" name="month" placeholder="мм.гггг"
               value="{{ $month?->format('m.Y') }}" style="max-width: 140px;">
        <button type="submit" class="secondary" style="margin-top: 0;">{{ __('charges.issue.now_preview') }}</button>
    </form>

    @if ($month)
        <h2>{{ Month::label($month) }}</h2>
        @if ($rows === [])
            <p class="muted">{{ __('charges.messages.nothing_to_issue') }}</p>
        @else
            <div class="table-wrap">
                <table>
                    <thead>
                    <tr>
                        <th>{{ __('charges.fields.property') }}</th>
                        <th>{{ __('charges.fields.type') }}</th>
                        <th class="num">{{ __('charges.fields.days') }}</th>
                        <th class="num">{{ __('charges.fields.amount') }}</th>
                        <th>{{ __('charges.fields.note') }}</th>
                    </tr>
                    </thead>
                    <tbody>
                    {{-- Битов ред със сума 0 не е в известието (PROJECT.md, „Битови сметки в месеца“). --}}
                    @foreach ($rows as $row)
                        @continue(isset($row['draft']) && $row['draft']->isUtility() && Cents::from((string) $row['amount']) === 0)
                        <tr>
                            <td>№ {{ $properties[$row['property_id']] ?? '?' }}</td>
                            <td>{{ __('charges.types.'.$row['type']->value) }}</td>
                            <td class="num">{{ $row['days'] }}</td>
                            <td class="num">{{ Format::money((string) $row['amount']) }}</td>
                            <td>
                                @if (isset($row['draft']) && $row['draft']->isUtility())
                                    @include('charges.partials.utility-label', ['row' => $row['draft']])
                                @endif
                                {{ $row['note'] }}
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            @foreach ($owners as $owner)
                <p class="muted">{{ __('charges.issue.now_owner') }}: № {{ $properties[$owner['property']] ?? '?' }} — {{ Format::money(Cents::format($owner['change'])) }} €</p>
            @endforeach

            <form method="POST" action="/lease-contracts/{{ $contract->id }}/issue">
                @csrf
                <input type="hidden" name="month" value="{{ $month->format('m.Y') }}">
                <label><input type="checkbox" name="reviewed" value="1"> {{ __('charges.issue.reviewed') }}</label>
                <div class="actions">
                    <button type="submit">{{ __('charges.issue.confirm') }}</button>
                    <a href="/lease-contracts/{{ $contract->id }}" style="margin-top: 18px;">{{ __('registry.cancel') }}</a>
                </div>
            </form>
        @endif
    @endif
@endsection
