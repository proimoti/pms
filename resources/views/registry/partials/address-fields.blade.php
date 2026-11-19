{{-- Структуриран адрес. $model е записът, $cityRequired дали градът е задължителен. --}}
<div class="grid">
    @foreach (['postal_code', 'city', 'district', 'street', 'street_number', 'entrance', 'floor', 'apartment'] as $field)
        <div>
            <label for="{{ $field }}">{{ __('registry.fields.'.$field) }}@if ($field === 'city' && ($cityRequired ?? false)) *@endif</label>
            <input id="{{ $field }}" type="text" name="{{ $field }}" value="{{ old($field, $model->{$field}) }}"
                   @if ($field === 'city' && ($cityRequired ?? false)) required @endif>
        </div>
    @endforeach
</div>
