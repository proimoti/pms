@extends('layouts.app')

@section('title', $staff->name)
@section('width', 'wide')

@section('content')
    <p><a href="/staff">{{ __('staff.back') }}</a></p>
    <h1>{{ $staff->name }}</h1>
    <p class="muted">{{ $staff->email }} · @include('staff.state', ['member' => $staff]) ·
        {{ __('staff.columns.two_factor') }}: {{ $staff->hasEnabledTwoFactorAuthentication() ? __('staff.two_factor_on') : __('staff.two_factor_off') }}</p>

    @error('invite')<div class="errors">{{ $message }}</div>@enderror

    @if ($self)
        <div class="warning">{{ __('staff.self_note') }}</div>
    @endif

    @php($f = 'staff.fields.')
    {{-- Права: шаблон или поотделно. --}}
    <h2>{{ __('staff.rights') }}</h2>
    <p class="muted">{{ __('staff.columns.template') }}: {{ $template ? __('staff.templates.'.$template) : __('staff.custom') }}</p>
    <form method="POST" action="/staff/{{ $staff->id }}">
        @csrf
        @method('PUT')
        <fieldset @disabled($self) style="border: 0; padding: 0; margin: 0;">
            <label for="name">{{ __($f.'name') }} *</label>
            <input id="name" type="text" name="name" value="{{ old('name', $staff->name) }}" required>

            <label for="template">{{ __($f.'template') }}</label>
            <select id="template" name="template">
                <option value="">{{ __($f.'template_keep') }}</option>
                @foreach (array_keys(\App\Support\StaffTemplates::all()) as $key)
                    <option value="{{ $key }}">{{ __('staff.templates.'.$key) }}: {{ __('staff.template_hints.'.$key) }}</option>
                @endforeach
            </select>

            <div class="grid">
                <div>
                    <label for="access_level">{{ __($f.'access_level') }}</label>
                    <select id="access_level" name="access_level">
                        @foreach (__('staff.access') as $value => $label)
                            <option value="{{ $value }}" @selected(old('access_level', $staff->access_level->value) === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="property_scope">{{ __($f.'property_scope') }}</label>
                    <select id="property_scope" name="property_scope">
                        @foreach (__('staff.scope') as $value => $label)
                            <option value="{{ $value }}" @selected(old('property_scope', $staff->property_scope->value) === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <label style="font-weight: normal;"><input type="checkbox" name="can_view_sensitive" value="1" @checked($staff->can_view_sensitive)> {{ __($f.'can_view_sensitive') }}</label>
            <label style="font-weight: normal;"><input type="checkbox" name="is_company_admin" value="1" @checked($staff->is_company_admin)> {{ __($f.'is_company_admin') }}</label>
            @unless ($self)<button type="submit">{{ __('staff.save') }}</button>@endunless
        </fieldset>
    </form>

    {{-- Прикачени имоти: всички имоти на фирмата, с търсене. --}}
    <h2>{{ __('staff.properties') }}</h2>
    <p class="muted">{{ __('staff.properties_hint') }}</p>
    @if ($properties->isEmpty())
        <p class="muted">{{ __('staff.properties_none') }}</p>
    @else
        <form method="POST" action="/staff/{{ $staff->id }}/properties">
            @csrf
            @method('PUT')
            <input type="search" id="property-filter" placeholder="{{ __('staff.properties_filter') }}" aria-label="{{ __('staff.properties_filter') }}" style="max-width: 360px;">
            <div class="table-wrap" style="max-height: 360px; overflow-y: auto; margin-top: 8px;">
                <table>
                    <tbody>
                    @foreach ($properties as $property)
                        @php($label = '№ '.$property->number.' '.\App\Support\Address::format($property))
                        <tr data-filter="{{ mb_strtolower($label) }}">
                            <td style="width: 32px;"><input type="checkbox" id="property-{{ $property->id }}" name="property_ids[]" value="{{ $property->id }}" @checked(in_array($property->id, $attached)) @disabled($self)></td>
                            <td><label for="property-{{ $property->id }}" style="margin: 0; font-weight: normal;">{{ $label }}</label></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            @unless ($self)<button type="submit">{{ __('staff.properties_save') }}</button>@endunless
        </form>
        <script>
            document.getElementById('property-filter').addEventListener('input', function () {
                const q = this.value.trim().toLowerCase();
                document.querySelectorAll('tr[data-filter]').forEach(function (row) {
                    row.style.display = q === '' || row.dataset.filter.includes(q) ? '' : 'none';
                });
            });
        </script>
    @endif

    @unless ($self)
        {{-- Достъп: спиране и връщане, покана отново. --}}
        <h2>{{ __('staff.access_title') }}</h2>
        <div class="row" style="justify-content: flex-start;">
            @if ($staff->isStopped())
                <form method="POST" action="/staff/{{ $staff->id }}/restore">@csrf<button type="submit" class="secondary" style="margin-top: 0;">{{ __('staff.restore') }}</button></form>
            @else
                <form method="POST" action="/staff/{{ $staff->id }}/stop" onsubmit="return confirm(@js(__('staff.stop_confirm')))">@csrf<button type="submit" class="secondary" style="margin-top: 0;">{{ __('staff.stop') }}</button></form>
            @endif
            @if ($staff->invitation_accepted_at === null && ! $staff->isStopped())
                <form method="POST" action="/staff/{{ $staff->id }}/invite">@csrf<button type="submit" class="secondary" style="margin-top: 0;">{{ __('staff.invite') }}</button></form>
            @endif
        </div>

        {{-- Втори фактор: нулиране с кода от телефона на администратора. --}}
        @if ($staff->two_factor_secret !== null)
            <h2>{{ __('staff.two_factor.title') }}</h2>
            <p class="muted">{{ __('staff.two_factor.intro') }}</p>
            <form method="POST" action="/staff/{{ $staff->id }}/reset-two-factor" class="row" style="justify-content: flex-start;">
                @csrf
                <input type="text" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" placeholder="{{ __('staff.two_factor.code') }}" aria-label="{{ __('staff.two_factor.code') }}" style="max-width: 200px;" required>
                <button type="submit" class="secondary" style="margin-top: 0;">{{ __('staff.two_factor.submit') }}</button>
            </form>
        @endif
    @endunless
@endsection
