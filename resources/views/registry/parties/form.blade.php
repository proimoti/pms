@extends('layouts.app')

@php($isNew = ! $party->exists)
@php($heading = $isNew ? __('registry.new.'.$section) : __('registry.one.'.$section).' № '.$party->number)
@php($kind = old('kind', $party->kind?->value ?? 'individual'))
@section('title', $heading)
@section('width', 'full')

@section('content')
    <h1>{{ $heading }}</h1>

    @if (session('similar_names'))
        <div class="warning">
            <strong>{{ __('registry.similar_warning') }}</strong>
            <ul>
                @foreach (session('similar_names') as $similar)
                    <li><a href="/{{ $section }}/{{ $similar['id'] }}" target="_blank">№ {{ $similar['number'] }} — {{ $similar['name'] }}</a></li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ $isNew ? '/'.$section : '/'.$section.'/'.$party->id }}">
        @csrf
        @unless ($isNew) @method('PUT') @endunless

        @if (session('similar_names'))
            <label style="font-weight: normal;">
                <input type="checkbox" name="confirm_similar_name" value="1"> {{ __('registry.similar_confirm') }}
            </label>
        @endif

        <div class="grid">
            <div>
                <label for="number">{{ __('registry.fields.number') }}@unless ($isNew) *@endunless</label>
                <input id="number" type="number" min="1" name="number" value="{{ old('number', $party->number) }}" @unless ($isNew) required @endunless>
                @if ($isNew)<div class="muted">{{ __('registry.number_hint') }}</div>@endif
            </div>
            <div>
                <label for="kind">{{ __('registry.fields.kind') }} *</label>
                <select id="kind" name="kind" required>
                    @foreach (__('registry.kinds') as $value => $label)
                        <option value="{{ $value }}" @selected($kind === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="name">{{ __('registry.fields.name') }} *</label>
                <input id="name" type="text" name="name" value="{{ old('name', $party->name) }}" required>
            </div>
            <div>
                <label for="eik">{{ __('registry.fields.eik') }}</label>
                <input id="eik" type="text" inputmode="numeric" name="eik" value="{{ old('eik', $party->eik) }}">
                <div class="muted">{{ __('registry.legal_only') }}</div>
            </div>
            <div>
                <label for="manager_name">{{ __('registry.fields.manager_name') }}</label>
                <input id="manager_name" type="text" name="manager_name" value="{{ old('manager_name', $party->manager_name) }}">
                <div class="muted">{{ __('registry.legal_only') }}</div>
            </div>
            <div>
                <label for="phone">{{ __('registry.fields.phone') }}</label>
                <input id="phone" type="text" name="phone" value="{{ old('phone', $party->phone) }}">
            </div>
            <div>
                <label for="email">{{ __('registry.fields.email') }}</label>
                <input id="email" type="email" name="email" value="{{ old('email', $party->email) }}">
            </div>
        </div>

        <h2>{{ __('registry.fields.address') }}</h2>
        <div class="grid">
            <div>
                <label for="country">{{ __('registry.fields.country') }}</label>
                <input id="country" type="text" name="country" maxlength="2" value="{{ old('country', $party->country ?? 'BG') }}">
            </div>
        </div>
        @include('registry.partials.address-fields', ['model' => $party])

        <label for="note">{{ __('registry.fields.note') }}</label>
        <textarea id="note" name="note">{{ old('note', $party->note) }}</textarea>

        <div class="actions">
            <button type="submit">{{ __('registry.save') }}</button>
            <a href="{{ $isNew ? '/'.$section : '/'.$section.'/'.$party->id }}" style="margin-top: 18px;">{{ __('registry.cancel') }}</a>
        </div>
    </form>
@endsection
