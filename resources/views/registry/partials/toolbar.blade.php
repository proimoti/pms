{{-- Заглавие, бутон „Нов“ (само при „четене и запис“), „Всички / Активни / Архивирани“ и търсене (24.11.2026). --}}
@php($canWrite = auth()->user()->access_level === \App\Enums\AccessLevel::Write)
@php($shown = \App\Http\Controllers\Registry\RegistryController::shown(request()))

<div class="row">
    <h1 style="margin: 0;">{{ __('registry.sections.'.$section) }}</h1>
    @if ($canWrite)
        <a class="button" style="margin-top: 0;" href="/{{ $section }}/create">{{ __('registry.new.'.$section) }}</a>
    @endif
</div>

<form method="GET" action="/{{ $section }}" class="row" style="margin: 18px 0 14px; justify-content: flex-start;">
    <div class="seg">
        @foreach (['all', 'active', 'archived'] as $show)
            <a href="/{{ $section }}?show={{ $show }}"@if ($shown === $show) aria-current="true"@endif>{{ __('registry.show_'.$show) }}</a>
        @endforeach
    </div>
    <input type="hidden" name="show" value="{{ $shown }}">
    <input type="search" name="q" value="{{ request('q') }}" placeholder="{{ __('registry.search') }}" aria-label="{{ __('registry.search') }}" style="max-width: 420px;">
    <button type="submit" class="secondary" style="margin-top: 0;">{{ __('registry.search_button') }}</button>
</form>
