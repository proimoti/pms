<!DOCTYPE html>
<html lang="bg">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title') – {{ config('app.name') }}</title>
    <style>
        /* Дизайн (21.11.2026): изчистен, светъл; системният шрифт, без външни файлове. */
        :root {
            --bg: #f6f7f9; --card: #fff; --text: #111827; --muted: #6b7280; --line: #e5e7eb; --line-strong: #d1d5db;
            --accent: #2563eb; --accent-soft: #eff6ff; --error: #b91c1c; --ok: #15803d; --warn: #b45309;
            --stripe-ok: #16a34a; --stripe-warning: #f59e0b; --stripe-off: #d1d5db; --hover: #f9fafb; --radius: 8px;
        }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; font-size: 15px; background: var(--bg); color: var(--text); }
        .shell { display: grid; grid-template-columns: 220px minmax(0, 1fr); min-height: 100vh; }
        nav.side { background: var(--card); border-right: 1px solid var(--line); padding: 18px 12px; font-size: 14px; position: sticky; top: 0; height: 100vh; overflow-y: auto; }
        nav.side .brand { font-weight: 600; font-size: 17px; padding: 0 10px 16px; display: block; color: var(--text); text-decoration: none; }
        nav.side .group { font-size: 12px; color: var(--muted); padding: 14px 10px 6px; }
        nav.side a.item { display: block; padding: 7px 10px; border-radius: var(--radius); color: var(--text); text-decoration: none; }
        nav.side a.item:hover { background: var(--hover); }
        nav.side a.item[aria-current="page"] { background: var(--accent-soft); color: var(--accent); font-weight: 600; }
        .page { display: flex; justify-content: center; padding: 32px 16px 48px; }
        .shell .page { justify-content: flex-start; padding: 28px 32px 48px; }
        main { width: 100%; max-width: 420px; background: var(--card); border: 1px solid var(--line); border-radius: 12px; padding: 28px; }
        main.wide { max-width: 680px; }
        main.full { max-width: 1120px; }
        main.board { max-width: 1120px; background: transparent; border: 0; padding: 0; }
        @media (max-width: 900px) {
            .shell { grid-template-columns: 1fr; }
            nav.side { position: static; height: auto; border-right: 0; border-bottom: 1px solid var(--line); display: flex; flex-wrap: wrap; gap: 2px 4px; align-items: center; }
            nav.side .group { width: 100%; padding: 6px 10px 2px; }
            .shell .page { padding: 18px 12px 36px; }
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
<body @class(['popup' => $popup])>
@if ($withMenu)
<div class="shell">
    <nav class="side" aria-label="{{ __('registry.menu') }}">
        <a class="brand" href="/dashboard">{{ config('app.name') }}</a>
        <a class="item" href="/dashboard" @if (request()->is('dashboard')) aria-current="page" @endif>{{ __('registry.menu') }}</a>
        @foreach (__('registry.menu_groups') as $group => $title)
            <div class="group">{{ $title }}</div>
            @foreach (__('registry.menu_items.'.$group) as $section)
                @continue(in_array($section, __('registry.menu_manage_only'), true) && ! \App\Models\Concerns\WorksheetRules::canManage(auth()->user()))
                <a class="item" href="/{{ $section }}" @if (request()->is($section, $section.'/*')) aria-current="page" @endif>{{ __('registry.sections.'.$section) }}</a>
            @endforeach
        @endforeach
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
