@extends('layouts.app')

@php($isNew = ! $fund->exists)
@php($heading = $isNew ? __('registry.new.funds') : __('registry.one.funds').' '.$fund->name)
@section('title', $heading)
@section('width', 'wide')

@section('content')
    <h1>{{ $heading }}</h1>
    <p class="muted">{{ __('utilities.funds.hint') }}</p>

    <form method="POST" action="{{ $isNew ? '/funds' : '/funds/'.$fund->id }}">
        @csrf
        @unless ($isNew) @method('PUT') @endunless

        <div class="grid">
            <div>
                <label for="name">{{ __('utilities.funds.fields.name') }} *</label>
                <input id="name" type="text" name="name" required maxlength="255" value="{{ old('name', $fund->name) }}">
            </div>
            <div>
                <label for="analytic">{{ __('utilities.funds.fields.analytic') }} *</label>
                <input id="analytic" type="text" name="analytic" required maxlength="50" value="{{ old('analytic', $fund->analytic) }}">
            </div>
        </div>

        <h2>{{ __('utilities.funds.fields.properties') }} *</h2>
        <div class="table-wrap" style="max-height: 360px; overflow-y: auto;">
            <table>
                <tbody>
                @foreach ($properties as $property)
                    <tr>
                        <td style="width: 32px;">
                            <input type="checkbox" id="property-{{ $property->id }}" name="properties[]" value="{{ $property->id }}" @checked(in_array($property->id, $selected, true))>
                        </td>
                        <td><label for="property-{{ $property->id }}" style="margin: 0; font-weight: normal;">№ {{ $property->number }} {{ \App\Support\Address::format($property) }}</label></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>

        <label for="note">{{ __('utilities.funds.fields.note') }}</label>
        <textarea id="note" name="note">{{ old('note', $fund->note) }}</textarea>

        <div class="actions">
            <button type="submit">{{ __('registry.save') }}</button>
            <a href="{{ $isNew ? '/funds' : '/funds/'.$fund->id }}" style="margin-top: 18px;">{{ __('registry.cancel') }}</a>
        </div>
    </form>
@endsection
