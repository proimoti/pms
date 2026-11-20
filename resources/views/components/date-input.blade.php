{{-- Поле за дата: текст дд.мм.гггг и до него бутон за календара на браузъра
     (PROJECT.md, „Дати в интерфейса“, 20.11.2026). Скритото поле за дата няма
     име: формата праща само текстовото. Поведението е в layouts/app. --}}
@props(['value' => null])
<span class="date-field">
    <input type="text" inputmode="numeric" maxlength="10" autocomplete="off" placeholder="{{ __('registry.date_placeholder') }}" data-date-text {{ $attributes }} value="{{ \App\Support\BgDate::toInput($value) }}">
    <button type="button" class="secondary date-pick" data-date-pick aria-label="{{ __('registry.date_pick') }}" title="{{ __('registry.date_pick') }}">&#128197;</button>
    <input type="date" class="date-native" data-date-native tabindex="-1" aria-hidden="true">
</span>
