{{-- Промени / Архивирай / Върни от архива — само при „четене и запис“. --}}
@php($canWrite = auth()->user()->access_level === \App\Enums\AccessLevel::Write)

@if ($record->archived_at)
    <div class="warning">{{ __('registry.is_archived') }}</div>
@endif

<div class="actions" style="margin-top: 18px;">
    <a href="/{{ $section }}">{{ __('registry.back_to_list') }}</a>
    @if ($canWrite)
        <a class="button" style="margin-top: 0;" href="/{{ $section }}/{{ $record->id }}/edit">{{ __('registry.edit') }}</a>
        @if ($record->archived_at)
            <form method="POST" action="/{{ $section }}/{{ $record->id }}/restore">
                @csrf
                <button type="submit" class="secondary" style="margin-top: 0;">{{ __('registry.restore') }}</button>
            </form>
        @else
            <form method="POST" action="/{{ $section }}/{{ $record->id }}/archive" onsubmit="return confirm(@js(__('registry.archive_confirm')));">
                @csrf
                <button type="submit" class="secondary" style="margin-top: 0;">{{ __('registry.archive') }}</button>
            </form>
        @endif
    @endif
</div>
