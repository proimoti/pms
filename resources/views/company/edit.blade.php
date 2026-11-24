@extends('layouts.app')

@section('title', __('company.edit'))
@section('width', 'wide')

@section('content')
    <h1>{{ __('company.title') }}: {{ __('company.edit') }}</h1>

    @php($f = 'company.fields.')
    <form method="POST" action="/company">
        @csrf
        @method('PUT')
        <div class="grid">
            @foreach (['name' => true, 'eik' => true, 'vat_number' => false, 'manager_name' => false, 'address' => false, 'email' => false, 'phone' => false] as $field => $required)
                <div>
                    <label for="{{ $field }}">{{ __($f.$field) }}@if ($required) *@endif</label>
                    <input id="{{ $field }}" type="{{ $field === 'email' ? 'email' : 'text' }}" name="{{ $field }}" value="{{ old($field, $company->{$field}) }}" @if ($required) required @endif>
                </div>
            @endforeach
        </div>
        <div class="actions">
            <button type="submit">{{ __('registry.save') }}</button>
            <a href="/company" style="margin-top: 18px;">{{ __('registry.cancel') }}</a>
        </div>
    </form>
@endsection
