{{-- Порталът в картата на наемателя или собственика (27.11.2026): състояние и бутони (само с „четене и запис“). --}}
@php($portalState = \App\Support\PortalAccess::state($party))
@php($canWrite = auth()->user()->access_level === \App\Enums\AccessLevel::Write)
<h2>{{ __('portal.card.title') }}</h2>
<div class="panel" style="margin-top: 0;">
    @error('portal')<div class="errors">{{ $message }}</div>@enderror
    <p style="margin-top: 0;">{{ __('portal.card.state') }}: <strong>{{ __('portal.state.'.$portalState) }}</strong></p>
    @if (trim((string) $party->email) === '' && $portalState === 'none')
        <p class="muted" style="margin-bottom: 0;">{{ __('portal.card.no_email') }}</p>
    @elseif ($canWrite)
        <div class="row" style="justify-content: flex-start;">
            @if ($portalState === 'none' && $party->archived_at === null)
                <form method="POST" action="/{{ $section }}/{{ $party->id }}/portal/invite">@csrf<button type="submit" class="secondary" style="margin-top: 0;">{{ __('portal.card.invite') }}</button></form>
            @endif
            @if ($portalState === 'invited')
                <form method="POST" action="/{{ $section }}/{{ $party->id }}/portal/invite">@csrf<button type="submit" class="secondary" style="margin-top: 0;">{{ __('portal.card.invite_again') }}</button></form>
            @endif
            @if (in_array($portalState, ['invited', 'active'], true))
                <form method="POST" action="/{{ $section }}/{{ $party->id }}/portal/stop" onsubmit="return confirm(@js(__('portal.card.stop_confirm')))">@csrf<button type="submit" class="secondary" style="margin-top: 0;">{{ __('portal.card.stop') }}</button></form>
            @endif
            @if ($portalState === 'stopped')
                <form method="POST" action="/{{ $section }}/{{ $party->id }}/portal/restore">@csrf<button type="submit" class="secondary" style="margin-top: 0;">{{ __('portal.card.restore') }}</button></form>
            @endif
        </div>
    @endif
</div>
