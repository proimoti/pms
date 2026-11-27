{{-- Състоянието на профила: спрян, поканен (чака парола), поканата е изтекла или активен. --}}
@if ($member->isStopped())
    {{ __('staff.state.stopped') }}
@elseif ($member->invitation_token !== null && $member->invitation_expires_at?->isFuture())
    {{ __('staff.state.invited') }}
@elseif ($member->invitation_token !== null)
    {{ __('staff.state.invite_expired') }}
@else
    {{ __('staff.state.active') }}
@endif
