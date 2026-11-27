@extends('layouts.app')

@section('title', __('export.settings.title'))
@section('width', 'wide')

@section('content')
    <h1>{{ __('export.settings.title') }}</h1>
    <p class="muted">{{ __('export.settings.intro') }}</p>

    @php($k = 'export.settings.keys.')
    @if ($missing !== [])
        <div class="warning">
            {{ __('export.settings.missing') }}
            {{ implode('; ', array_map(fn ($key) => str_starts_with($key, 'kind_') ? __('utilities.kinds.'.substr($key, 5)) : __($k.$key), $missing)) }}
        </div>
    @endif

    @php($field = function (string $key, string $label, ?string $hint = null) use ($values) {
        return ['key' => $key, 'label' => $label, 'hint' => $hint, 'value' => old($key, $values[$key] ?? '')];
    })
    @php($groups = [
        'synthetic' => array_map(fn ($key) => $field($key, __($k.$key), __('export.settings.hints.synthetic')), ['tenant', 'deposit', 'owner', 'utility', 'fund']),
        'revenue' => array_map(fn ($type) => $field('revenue_'.$type->value, __('contracts.types.'.$type->value),
            $type === \App\Enums\OwnerContractType::Own ? __('export.settings.hints.revenue_own') : __('export.settings.hints.account')), \App\Enums\OwnerContractType::cases()),
        'other' => array_map(fn ($key) => $field($key, __($k.$key), __('export.settings.hints.account')), ['revenue_penalty', 'revenue_other', 'manager_cost', 'deposit_income']),
        'paid' => array_map(fn ($key) => $field($key, __($k.$key), __('export.settings.hints.account')), ['bank_epay', 'bank_easypay']),
        'kinds' => array_map(fn ($kind) => $field('kind_'.$kind->value, __('utilities.kinds.'.$kind->value)), \App\Enums\UtilityKind::cases()),
    ])

    <form method="POST" action="/company/export">
        @csrf
        @method('PUT')
        @foreach ($groups as $group => $fields)
            <h2>{{ __('export.settings.groups.'.$group) }}</h2>
            @if ($group === 'paid')
                <label style="font-weight: normal;">
                    <input type="checkbox" name="include_paid_bills" value="1" @checked(old('include_paid_bills', $values['include_paid_bills'] ?? '0') === '1')>
                    {{ __($k.'include_paid_bills') }}
                </label>
            @endif
            @if ($group === 'kinds')
                <p class="muted">{{ __('export.settings.hints.code') }}</p>
            @endif
            <div class="grid">
                @foreach ($fields as $f)
                    <div>
                        <label for="{{ $f['key'] }}">{{ $f['label'] }}</label>
                        <input id="{{ $f['key'] }}" type="text" name="{{ $f['key'] }}" value="{{ $f['value'] }}" maxlength="50">
                        @if ($f['hint'])<div class="muted">{{ $f['hint'] }}</div>@endif
                    </div>
                @endforeach
            </div>
        @endforeach

        <div class="actions">
            <button type="submit">{{ __('registry.save') }}</button>
            <a href="/company" style="margin-top: 18px;">{{ __('registry.cancel') }}</a>
        </div>
    </form>
@endsection
