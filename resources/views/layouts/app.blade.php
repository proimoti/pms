<!DOCTYPE html>
<html lang="bg">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title') – {{ config('app.name') }}</title>
    <style>
        :root { --bg: #f5f5f4; --card: #fff; --text: #1c1917; --muted: #57534e; --line: #d6d3d1; --accent: #1d4ed8; --error: #b91c1c; --ok: #15803d; --warn: #92400e; }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; background: var(--bg); color: var(--text); }
        .page { display: flex; justify-content: center; padding: 32px 16px 48px; }
        main { width: 100%; max-width: 420px; background: var(--card); border: 1px solid var(--line); border-radius: 10px; padding: 28px; }
        main.wide { max-width: 640px; }
        main.full { max-width: 1100px; }
        nav.top { background: var(--card); border-bottom: 1px solid var(--line); padding: 10px 16px; display: flex; gap: 18px; flex-wrap: wrap; align-items: center; justify-content: center; }
        nav.top a { text-decoration: none; color: var(--text); padding: 4px 2px; }
        nav.top a.active { color: var(--accent); font-weight: 600; border-bottom: 2px solid var(--accent); }
        h1 { font-size: 1.4rem; margin: 0 0 18px; }
        h2 { font-size: 1.1rem; margin: 22px 0 10px; }
        p { line-height: 1.5; }
        label { display: block; font-weight: 600; margin: 14px 0 6px; }
        input[type=email], input[type=password], input[type=text], input[type=number], input[type=search], select, textarea { width: 100%; padding: 10px 12px; font-size: 1rem; border: 1px solid var(--line); border-radius: 6px; background: #fff; }
        textarea { min-height: 90px; }
        button, .button { display: inline-block; margin-top: 18px; padding: 10px 16px; font-size: 1rem; border: 0; border-radius: 6px; background: var(--accent); color: #fff; cursor: pointer; text-decoration: none; }
        button.secondary, .button.secondary { background: #e7e5e4; color: var(--text); }
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
    </style>
</head>
<body>
@auth
    @if (auth()->user()->role === \App\Enums\Role::Staff)
        <nav class="top">
            <a href="/dashboard" @class(['active' => request()->is('dashboard')])>{{ __('registry.menu') }}</a>
            @foreach (__('registry.sections') as $section => $title)
                <a href="/{{ $section }}" @class(['active' => request()->is($section, $section.'/*')])>{{ $title }}</a>
            @endforeach
        </nav>
    @endif
@endauth
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
</body>
</html>
