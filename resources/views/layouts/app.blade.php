<!DOCTYPE html>
<html lang="bg">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title') – {{ config('app.name') }}</title>
    <style>
        /* Общ вид (24.11.2026, вариант Б от макетите): тъмно меню вляво, светли карти върху сив фон; системният шрифт, без външни файлове. */
        :root {
            --bg: #eef0f4; --card: #fff; --text: #111827; --muted: #5b6472; --line: #e3e6eb; --line-strong: #c9ced6;
            --accent: #1d4ed8; --accent-soft: #eff6ff; --error: #b42318; --ok: #1e6b3f; --warn: #7a4f00;
            --stripe-ok: #16a34a; --stripe-warning: #f59e0b; --stripe-off: #d1d5db; --hover: #f1f5fd; --radius: 8px;
            --menu: #16213a; --menu-text: #d6dce8; --menu-hover: #22304f; --menu-current: #2d4170; --menu-group: #9aa7c0; --menu-line: #2d3b5c; --menu-soft: #b8c2d6; --menu-link: #a9c4ff;
        }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; font-size: 15px; background: var(--bg); color: var(--text); }
        .shell { display: grid; grid-template-columns: 250px minmax(0, 1fr); min-height: 100vh; }
        nav.side { background: var(--menu); padding: 20px 12px; font-size: 14px; position: sticky; top: 0; height: 100vh; overflow-y: auto; display: flex; flex-direction: column; gap: 2px; }
        nav.side .brand { font-weight: 700; font-size: 18px; padding: 0 12px 10px; display: block; color: #fff; text-decoration: none; }
        nav.side .group { font-size: 11px; text-transform: uppercase; letter-spacing: .06em; color: var(--menu-group); padding: 18px 12px 6px; margin: 0; font-weight: 600; }
        nav.side a.item { display: block; padding: 7px 12px; border-radius: 6px; color: var(--menu-text); text-decoration: none; }
        nav.side a.item.sub { padding-left: 26px; }
        nav.side a.item:hover { background: var(--menu-hover); color: #fff; }
        nav.side a.item[aria-current="page"] { background: var(--menu-current); color: #fff; font-weight: 600; }
        nav.side .side-foot { margin-top: auto; padding: 16px 12px 0; border-top: 1px solid var(--menu-line); display: flex; flex-direction: column; gap: 2px; }
        nav.side .side-foot .who { font-weight: 600; color: #fff; }
        nav.side .side-foot .firm { font-size: 13px; color: var(--menu-soft); }
        nav.side .side-foot form { margin: 6px 0 0; }
        nav.side .side-foot button { margin: 0; padding: 0; border: 0; background: none; color: var(--menu-link); font-size: 14px; cursor: pointer; }
        nav.side .side-foot button:hover { text-decoration: underline; }
        .page { display: flex; justify-content: center; padding: 32px 16px 48px; }
        .shell .page { justify-content: flex-start; padding: 32px 40px 48px; }
        main { width: 100%; max-width: 420px; background: var(--card); border: 1px solid var(--line); border-radius: 12px; padding: 28px; }
        main.list, main.board { max-width: 1600px; }
        main.full, main.wide { max-width: 1240px; }
        body:not(.with-menu) main.wide { max-width: 680px; }
        /* С менюто: страницата е на сивия фон, съдържанието е в светли карти. */
        .shell main { background: transparent; border: 0; padding: 0; }
        .shell main:not(.board) > dl, .shell main:not(.board) > form:not(.row), .shell main:not(.board) .table-wrap, .shell main:not(.board) > ul { background: var(--card); border: 1px solid var(--line); border-radius: 10px; }
        .shell main > dl { padding: 18px 20px; }
        .shell main > form:not(.row) { padding: 8px 24px 24px; }
        .shell main > ul { padding: 14px 20px 14px 38px; }
        .shell main:not(.board) .table-wrap table { margin-top: 0; }
        .shell main:not(.board) .table-wrap th, .shell main:not(.board) .table-wrap td { padding: 10px 14px; }
        .shell main:not(.board) .table-wrap th { white-space: nowrap; font-size: 12px; }
        .shell main:not(.board) .table-wrap tbody tr:last-child td { border-bottom: 0; }
        .shell main:not(.board) h1 { font-size: 28px; font-weight: 700; }
        tbody tr[data-href] { cursor: pointer; }
        tbody tr[data-href]:hover { background: var(--hover); }
        .overpaid { color: var(--ok); font-weight: 600; }
        .dossier-pair { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 0 24px; align-items: start; }
        @media (max-width: 900px) { .dossier-pair { grid-template-columns: 1fr; } }
        .seg { display: inline-flex; background: var(--card); border: 1px solid var(--line-strong); border-radius: var(--radius); overflow: hidden; }
        .seg a { display: inline-flex; align-items: center; min-height: 36px; padding: 0 14px; font-size: 14px; text-decoration: none; color: var(--text); }
        .seg a:hover { background: var(--hover); }
        .seg a[aria-current="true"] { background: var(--text); color: #fff; font-weight: 600; }
        @media (max-width: 900px) {
            .shell { grid-template-columns: 1fr; }
            nav.side { position: static; height: auto; flex-direction: row; flex-wrap: wrap; gap: 2px 4px; align-items: center; }
            nav.side .group { width: 100%; padding: 6px 12px 2px; }
            nav.side a.item.sub { padding-left: 12px; }
            nav.side .side-foot { width: 100%; margin-top: 8px; }
            .shell .page { padding: 18px 16px 36px; }
        }
        h1 { font-size: 1.45rem; font-weight: 600; margin: 0 0 18px; }
        h2 { font-size: 1.1rem; margin: 22px 0 10px; }
        p { line-height: 1.5; }
        label { display: block; font-weight: 600; margin: 14px 0 6px; }
        input[type=email], input[type=password], input[type=text], input[type=number], input[type=search], select, textarea { width: 100%; padding: 9px 12px; font-size: 15px; font-family: inherit; border: 1px solid var(--line-strong); border-radius: var(--radius); background: #fff; color: var(--text); }
        input:focus, select:focus, textarea:focus { outline: 2px solid var(--accent-soft); border-color: var(--accent); }
        textarea { min-height: 90px; }
        button, .button { display: inline-block; margin-top: 18px; padding: 9px 16px; font-size: 15px; font-family: inherit; border: 1px solid var(--accent); border-radius: var(--radius); background: var(--accent); color: #fff; cursor: pointer; text-decoration: none; }
        button.secondary, .button.secondary { background: #fff; color: var(--text); border-color: var(--line-strong); }
        button.secondary:hover, .button.secondary:hover { background: var(--hover); }
        a { color: var(--accent); }
        .muted { color: var(--muted); font-size: .9rem; }
        .errors { color: var(--error); background: #fef2f2; border: 1px solid #fecaca; border-radius: 6px; padding: 10px 12px; margin-bottom: 12px; }
        .status { color: var(--ok); background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 6px; padding: 10px 12px; margin-bottom: 12px; }
        .warning { color: var(--warn); background: #fffbeb; border: 1px solid #fde68a; border-radius: 6px; padding: 10px 12px; margin-bottom: 12px; }
        .row { display: flex; gap: 12px; align-items: center; justify-content: space-between; flex-wrap: wrap; }
        .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 0 14px; }
        .actions { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }
        .actions form { margin: 0; }
        table { width: 100%; border-collapse: collapse; margin-top: 14px; }
        th, td { text-align: left; padding: 8px 10px; border-bottom: 1px solid var(--line); vertical-align: top; }
        th { font-size: .85rem; color: var(--muted); font-weight: 600; }
        td.num, th.num { text-align: right; white-space: nowrap; }
        .table-wrap { overflow-x: auto; }
        dl { display: grid; grid-template-columns: max-content 1fr; gap: 6px 18px; margin: 0; }
        dt { color: var(--muted); }
        dd { margin: 0; }
        .pagination { display: flex; gap: 14px; justify-content: center; margin-top: 16px; }
        code, .codes { font-family: ui-monospace, Consolas, monospace; }
        .codes { columns: 2; padding-left: 18px; }
        .date-field { position: relative; display: flex; gap: 6px; align-items: center; }
        .date-field input[type=text] { flex: 1; min-width: 7.5em; }
        .date-field .date-pick { margin-top: 0; padding: 8px 10px; line-height: 1; }
        .date-field .date-native { position: absolute; right: 0; bottom: 0; width: 1px; height: 1px; padding: 0; border: 0; opacity: 0; pointer-events: none; }
        .with-new { display: flex; gap: 6px; align-items: center; }
        .with-new select { flex: 1; min-width: 0; }
        .with-new .quick-new-button { margin-top: 0; padding: 8px 10px; white-space: nowrap; }
        dialog.quick-new { width: min(820px, 96vw); height: min(88vh, 960px); padding: 0; border: 1px solid var(--line); border-radius: 10px; }
        dialog.quick-new::backdrop { background: rgba(28, 25, 23, .45); }
        dialog.quick-new .quick-new-bar { display: flex; justify-content: flex-end; padding: 8px 10px; border-bottom: 1px solid var(--line); }
        dialog.quick-new .quick-new-bar button { margin-top: 0; }
        dialog.quick-new iframe { width: 100%; height: calc(100% - 55px); border: 0; display: block; }
        body.popup .page { padding: 12px; }
        tr.zero td { color: var(--muted); }
        tr.removed td { color: var(--muted); text-decoration: line-through; }
        tr.removed td:nth-last-child(-n+3) { text-decoration: none; }
        tr.flagged td:first-child { border-left: 3px solid #f59e0b; }
        tfoot th { color: var(--text); }

        /* Общият елемент „хармоника“ (x-accordion): ред, който се разгъва с клик. */
        .accordion-list { background: var(--card); border: 1px solid var(--line); border-radius: 12px; overflow: hidden; }
        details.accordion { border-top: 1px solid var(--line); border-left: 4px solid var(--stripe-ok); }
        details.accordion:first-child { border-top: 0; }
        details.accordion.state-warning { border-left-color: var(--stripe-warning); }
        details.accordion.state-off { border-left-color: var(--stripe-off); }
        details.accordion > summary { list-style: none; display: grid; grid-template-columns: 22px minmax(0, 1fr) auto auto; gap: 12px; align-items: center; padding: 12px 16px; cursor: pointer; }
        details.accordion > summary::-webkit-details-marker { display: none; }
        details.accordion > summary:hover { background: var(--hover); }
        details.accordion > summary::before { content: "›"; font-size: 20px; line-height: 1; color: var(--muted); transition: transform .15s; }
        details.accordion[open] > summary::before { transform: rotate(90deg); }
        details.accordion .acc-title { font-weight: 600; }
        details.accordion .acc-sub { font-size: 13px; color: var(--muted); margin-top: 2px; }
        details.accordion .acc-sub.warn { color: var(--warn); }
        details.accordion .acc-meta { font-size: 13px; color: var(--muted); white-space: nowrap; }
        details.accordion .acc-amount { font-weight: 600; white-space: nowrap; font-variant-numeric: tabular-nums; text-align: right; min-width: 100px; }
        details.accordion .acc-body { background: var(--hover); padding: 4px 16px 14px 50px; }
        details.accordion .acc-body table { margin-top: 6px; background: var(--card); border: 1px solid var(--line); border-radius: var(--radius); }
        .metrics { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px; margin: 18px 0; }
        .metric { background: var(--card); border: 1px solid var(--line); border-radius: 12px; padding: 12px 16px; }
        .metric .label { font-size: 15px; font-weight: 600; color: var(--text); }
        .metric .value { font-size: 21px; font-weight: 600; margin-top: 2px; font-variant-numeric: tabular-nums; }
        .metric .value.negative { color: var(--error); }
        .metric .breakdown { font-size: 12px; color: var(--muted); margin-top: 6px; line-height: 1.6; }
        .metric .breakdown span { display: inline-block; margin-right: 10px; white-space: nowrap; }
        .badge { display: inline-block; font-size: 13px; font-weight: 600; padding: 3px 10px; border-radius: 999px; vertical-align: middle; }
        .badge.working { background: var(--accent-soft); color: var(--accent); }
        .badge.issued { background: #f0fdf4; color: var(--ok); }
        .badge.state-active { background: #e4f3ea; color: var(--ok); }
        .badge.state-upcoming { background: var(--accent-soft); color: var(--accent); }
        .badge.state-terminated, .badge.state-archived, .badge.state-empty { background: #eef0f3; color: #4a5160; }
        .month-switch { display: inline-flex; align-items: center; gap: 4px; }
        .month-switch a { display: inline-flex; width: 30px; height: 30px; align-items: center; justify-content: center; border: 1px solid var(--line-strong); border-radius: var(--radius); text-decoration: none; color: var(--text); background: var(--card); font-size: 18px; }
        .month-switch a:hover { background: var(--hover); }
        .board-head .title-line { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
        .warning a { color: inherit; }
        .board-head { display: flex; justify-content: space-between; align-items: flex-end; gap: 12px; flex-wrap: wrap; }
        .board-head .eyebrow { font-size: 13px; color: var(--muted); }
        .board-head h1 { margin: 2px 0 0; }
        .panel { background: var(--card); border: 1px solid var(--line); border-radius: 12px; padding: 16px 18px; margin-top: 18px; }
        .toolbar { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; margin: 18px 0 10px; }
        .toolbar input[type=search] { flex: 1; min-width: 200px; }
        .toolbar select { width: auto; }
        .toolbar button, .board-head .actions button, .board-head .actions .button { margin-top: 0; }
    </style>
</head>
{{-- Малкият прозорец „+ Нов“ (?popup=1): формата е без менюто. --}}
@php($popup = request()->boolean('popup') || old('popup') === '1')
{{-- Страничното меню е за служителите; входът и малкият прозорец „+ Нов“ са без него. --}}
@php($withMenu = auth()->check() && auth()->user()->role === \App\Enums\Role::Staff && ! $popup)
<body @class(['popup' => $popup, 'with-menu' => $withMenu])>
@if ($withMenu)
<div class="shell">
    <nav class="side" aria-label="{{ __('registry.menu') }}">
        <a class="brand" href="/dashboard">{{ config('app.name') }}</a>
        <a class="item" href="/dashboard" @if (request()->is('dashboard')) aria-current="page" @endif>{{ __('registry.menu') }}</a>
        @foreach (__('registry.menu_groups') as $group => $title)
            <p class="group">{{ $title }}</p>
            @foreach (__('registry.menu_items.'.$group) as $section)
                @continue(in_array($section, __('registry.menu_manage_only'), true) && ! \App\Models\Concerns\WorksheetRules::canManage(auth()->user()))
                <a class="item sub" href="/{{ $section }}" @if (request()->is($section, $section.'/*')) aria-current="page" @endif>{{ __('registry.sections.'.$section) }}</a>
            @endforeach
        @endforeach
        <div class="side-foot">
            <span class="who">{{ auth()->user()->name }}</span>
            <span class="firm">{{ auth()->user()->company?->name }}</span>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit">{{ __('registry.logout') }}</button>
            </form>
        </div>
    </nav>
@endif
<div class="page">
<main class="@yield('width')">
    @if (session('status') && is_string(session('status')) && ! str_starts_with(session('status'), 'two-factor'))
        <div class="status">{{ session('status') }}</div>
    @endif
    @if (session('warning'))
        <div class="warning">{{ session('warning') }}</div>
    @endif
    @if ($errors->any())
        <div class="errors">
            @foreach ($errors->all() as $error)
                <div>{{ $error }}</div>
            @endforeach
        </div>
    @endif

    @yield('content')
</main>
</div>
@if ($withMenu)
</div>
@endif
<script>
    (function () {
        // Календар до полето за дата (PROJECT.md, „Дати в интерфейса“): бутонът
        // отваря календара на браузъра, изборът се пише като дд.мм.гггг.
        function toIso(text) {
            const m = /^\s*(\d{1,2})\.(\d{1,2})\.(\d{4})\s*$/.exec(text);
            return m ? m[3] + '-' + m[2].padStart(2, '0') + '-' + m[1].padStart(2, '0') : '';
        }

        document.addEventListener('click', function (event) {
            const button = event.target.closest('[data-date-pick]');
            if (!button) {
                return;
            }
            const field = button.closest('.date-field');
            const picker = field.querySelector('[data-date-native]');
            picker.value = toIso(field.querySelector('[data-date-text]').value);
            try {
                picker.showPicker();
            } catch (error) {
                picker.focus();
                picker.click();
            }
        });

        document.addEventListener('change', function (event) {
            if (!event.target.matches('[data-date-native]') || !event.target.value) {
                return;
            }
            const [year, month, day] = event.target.value.split('-');
            const text = event.target.closest('.date-field').querySelector('[data-date-text]');
            text.value = day + '.' + month + '.' + year;
            text.dispatchEvent(new Event('input', { bubbles: true }));
            text.dispatchEvent(new Event('change', { bubbles: true }));
            text.focus();
        });

        // Връзка към ред от хармоника (#lease-12): редът се разгъва и се показва.
        function openTarget() {
            const target = window.location.hash ? document.getElementById(window.location.hash.slice(1)) : null;
            if (target && target.tagName === 'DETAILS') {
                target.open = true;
                target.scrollIntoView({ block: 'start' });
            }
        }
        window.addEventListener('hashchange', openTarget);
        document.addEventListener('DOMContentLoaded', openTarget);

        // В списъците целият ред води към картата (връзките и бутоните в реда си остават свои).
        document.addEventListener('click', function (event) {
            const row = event.target.closest('tr[data-href]');
            if (!row || event.target.closest('a, button, input, select, textarea, label')) {
                return;
            }
            if (event.ctrlKey || event.metaKey) {
                window.open(row.dataset.href, '_blank');
            } else {
                window.location.href = row.dataset.href;
            }
        });

        // В малкия прозорец „Отказ“ го затваря.
        document.addEventListener('click', function (event) {
            if (event.target.closest('[data-popup-cancel]') && window.parent !== window) {
                event.preventDefault();
                window.parent.postMessage({ type: 'pms-quick-new-cancel' }, window.location.origin);
            }
        });
    })();
</script>
</body>
</html>
