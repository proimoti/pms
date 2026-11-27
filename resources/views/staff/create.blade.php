@extends('layouts.app')

@section('title', __('staff.new'))
@section('width', 'wide')

@section('content')
    <h1>{{ __('staff.new') }}</h1>
    <p class="muted">{{ __('staff.intro') }}</p>

    @php($f = 'staff.fields.')
    <form method="POST" action="/staff">
        @csrf
        <div class="grid">
            <div>
                <label for="name">{{ __($f.'name') }} *</label>
                <input id="name" type="text" name="name" value="{{ old('name') }}" required>
            </div>
            <div>
                <label for="email">{{ __($f.'email') }} *</label>
                <input id="email" type="email" name="email" value="{{ old('email') }}" required>
            </div>
        </div>

        <label for="template">{{ __($f.'template') }} *</label>
        <select id="template" name="template" required>
            @foreach (array_keys(\App\Support\StaffTemplates::all()) as $key)
                <option value="{{ $key }}" @selected(old('template') === $key)>{{ __('staff.templates.'.$key) }}: {{ __('staff.template_hints.'.$key) }}</option>
            @endforeach
        </select>

        <label style="font-weight: normal;"><input type="checkbox" name="is_company_admin" value="1" @checked(old('is_company_admin'))> {{ __($f.'is_company_admin') }}</label>

        <div class="actions">
            <button type="submit">{{ __('staff.create_submit') }}</button>
            <a href="/staff" style="margin-top: 18px;">{{ __('registry.cancel') }}</a>
        </div>
    </form>
@endsection
