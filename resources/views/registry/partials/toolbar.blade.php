{{-- Заглавие, бутон „Нов“ (само при „четене и запис“), търсене и активни/архивирани. --}}
@php($canWrite = auth()->user()->access_level === \App\Enums\AccessLevel::Write)
@php($archived = request()->boolean('archived'))

<div class="row">
    <h1 style="margin: 0;">{{ __('registry.sections.'.$section) }}</h1>
    @if ($canWrite)
        <a class="button" style="margin-top: 0;" href="/{{ $section }}/create">{{ __('registry.new.'.$section) }}</a>
    @endif
</div>

<form method="GET" action="/{{ $section }}" class="row" style="margin-top: 14px; justify-content: flex-start;">
    <input type="search" name="q" value="{{ request('q') }}" placeholder="{{ __('registry.search') }}" aria-label="{{ __('registry.search') }}" style="max-width: 360px;">
    @if ($archived)
        <input type="hidden" name="archived" value="1">
    @endif
    <button type="submit" class="secondary" style="margin-top: 0;">{{ __('registry.search_button') }}</button>
    <span class="muted">
        @if ($archived)
            <a href="/{{ $section }}">{{ __('registry.show_active') }}</a> · <strong>{{ __('registry.show_archived') }}</strong>
        @else
            <strong>{{ __('registry.show_active') }}</strong> · <a href="/{{ $section }}?archived=1">{{ __('registry.show_archived') }}</a>
        @endif
    </span>
</form>
