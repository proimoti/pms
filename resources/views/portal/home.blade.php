@extends('layouts.portal')

@section('title', __('portal.home.title'))

@section('content')
    <h1>{{ __('portal.home.hello', ['name' => auth()->user()->name]) }}</h1>
    <p class="muted">{{ __('portal.home.intro') }}</p>

    @if ($tenants->isNotEmpty())
        <h2>{{ __('portal.home.as_tenant') }}</h2>
        @foreach ($tenants as $tenant)
            <a href="/portal/tenants/{{ $tenant->id }}" class="card" style="display: block; color: inherit; text-decoration: none;">
                <strong>{{ $tenant->name }}</strong>
                <div>{{ __('portal.tenant.owes') }}: <strong>@include('portal.owes', ['amount' => $owes[$tenant->id]])</strong></div>
                <div style="color: var(--accent);">{{ __('portal.tenant.open') }} →</div>
            </a>
        @endforeach
    @endif

    @if ($owners->isNotEmpty())
        <h2>{{ __('portal.home.as_owner') }}</h2>
        @foreach ($owners as $owner)
            <div class="card">{{ $owner->name }}</div>
        @endforeach
    @endif
@endsection
