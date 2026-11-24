@extends('layouts.app')

@use('App\Support\Format')
@use('App\Services\Charges\Month')

@section('title', __('home.title'))
@section('width', 'list')

@php($user = auth()->user())
@php($canWrite = $user->access_level === \App\Enums\AccessLevel::Write)

@section('content')
    <style>
        .home-head { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 12px; margin-bottom: 24px; }
        .home-head h1 { margin: 0 0 4px; }
        .home-head .actions .button { margin-top: 0; min-height: 44px; display: inline-flex; align-items: center; font-weight: 600; }
        .home-band { background: var(--menu); color: #fff; border-radius: 12px; padding: 22px 24px; display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 20px; }
        .home-band > div { display: flex; flex-direction: column; gap: 6px; }
        .home-band .label, .home-band .sub { font-size: 13px; color: var(--menu-soft); }
        .home-band .big { font-size: 24px; font-weight: 700; font-variant-numeric: tabular-nums; }
        .home-band .state { font-size: 18px; font-weight: 700; }
        .home-band .gain { color: #8ee0b0; }
        .home-band a { color: var(--menu-link); font-size: 14px; }
        .home-lists { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 20px; margin-top: 24px; }
        .home-card { background: var(--card); border: 1px solid var(--line); border-radius: 12px; overflow: hidden; }
        .home-card h2 { margin: 0; padding: 16px 20px; font-size: 16px; font-weight: 600; }
        .home-card p { margin: 0; padding: 14px 20px; border-top: 1px solid var(--line); }
        a.home-row { display: flex; align-items: center; justify-content: space-between; gap: 16px; padding: 14px 20px; border-top: 1px solid var(--line); text-decoration: none; color: var(--text); font-size: 14px; }
        a.home-row:hover { background: var(--hover); }
        .home-row .count { font-weight: 700; padding: 2px 10px; border-radius: 999px; color: var(--warn); background: #fdf2dc; }
        .home-row .count.error { color: var(--error); background: #fdecea; }
        .home-row .meta { color: var(--muted); white-space: nowrap; }
        @media (max-width: 900px) { .home-band, .home-lists { grid-template-columns: 1fr; } }
    </style>

    <div class="home-head">
        <div>
            <h1>{{ __('home.title') }}</h1>
            <span class="muted">{{ $home['today']->locale('bg')->isoFormat('dddd') }}, {{ Format::date($home['today']) }}</span>
        </div>
        <div class="actions">
            @if ($home['canManage'])
                <a class="button secondary" href="/utility-imports">{{ __('home.buttons.import') }}</a>
            @endif
            @if ($canWrite)
                <a class="button secondary" href="/lease-contracts/create">{{ __('home.buttons.new_lease') }}</a>
            @endif
            @if ($home['canManage'])
                <a class="button" href="/charges?month={{ $home['key'] }}">{{ __('home.buttons.generate') }}</a>
            @endif
        </div>
    </div>

    <section class="home-band" id="home-month">
        <div>
            <span class="label">{{ Month::label($home['month']) }}</span>
            <span class="state">{{ __('home.states.'.$home['state']) }}</span>
            <a href="/charges?month={{ $home['key'] }}">{{ __('home.buttons.worksheet') }}</a>
        </div>
        <div>
            <span class="label">{{ __('home.occupancy') }}</span>
            <span class="big">{{ $home['occupancy']['percent'] }} %</span>
            <span class="sub">{{ __('home.occupancy_of', ['occupied' => $home['occupancy']['occupied'], 'total' => $home['occupancy']['total']]) }}</span>
        </div>
        <div>
            <span class="label">{{ __('home.rent') }}</span>
            @if ($home['totals'])
                <span class="big">{{ Format::money($home['totals']['rent']) }} €</span>
                <span class="sub">{{ __('home.to_owners', ['amount' => Format::money($home['totals']['owners'])]) }}</span>
            @else
                <span class="big">{{ __('registry.none') }}</span>
            @endif
        </div>
        <div>
            <span class="label">{{ __('home.commission') }}</span>
            <span class="big gain">{{ $home['totals'] ? Format::money($home['totals']['commission']).' €' : __('registry.none') }}</span>
        </div>
    </section>

    <div class="home-lists">
        <section class="home-card" id="home-decide">
            <h2>{{ __('home.decide.title') }}</h2>
            @forelse ($home['decide'] as $row)
                <a class="home-row" href="{{ $row['url'] }}"><span>{{ __('home.decide.'.$row['key'], ['month' => mb_strtolower(__('charges.months.'.$home['month']->month))]) }}</span><span class="count{{ $row['tone'] === 'error' ? ' error' : '' }}">{{ $row['count'] }}</span></a>
            @empty
                <p class="muted">{{ __('home.decide.nothing') }}</p>
            @endforelse
        </section>

        <section class="home-card" id="home-follow">
            <h2>{{ __('home.follow.title') }}</h2>
            @foreach ($home['follow']['expiring'] as $row)
                <a class="home-row" href="/lease-contracts/{{ $row['contract']->id }}"><span>{{ __('home.follow.expiring', ['number' => $row['contract']->number, 'name' => $row['contract']->tenant?->name, 'date' => Format::date($row['end_on'])]) }}</span><span class="meta">{{ __('home.follow.days', ['days' => $row['days']]) }}</span></a>
            @endforeach
            @foreach ($home['follow']['expired'] as $row)
                <a class="home-row" href="/{{ $row['kind'] === 'lease' ? 'lease-contracts' : 'owner-contracts' }}/{{ $row['contract']->id }}"><span>{{ __($row['kind'] === 'lease' ? 'home.follow.expired' : 'home.follow.expired_owner', ['number' => $row['contract']->number, 'name' => $row['party']]) }}</span><span class="meta">{{ __('home.follow.since', ['date' => Format::date($row['end_on'])]) }}</span></a>
            @endforeach
            @if ($home['follow']['vacant'] > 0)
                <a class="home-row" href="/reports/vacant"><span>{{ __('home.follow.vacant') }}</span><span class="meta">{{ $home['follow']['vacant'] }}</span></a>
            @endif
            @if ($home['follow']['expiring'] === [] && $home['follow']['expired'] === [] && $home['follow']['vacant'] === 0)
                <p class="muted">{{ __('home.follow.nothing') }}</p>
            @endif
        </section>
    </div>
@endsection
