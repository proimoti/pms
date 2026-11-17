<!DOCTYPE html>
<html lang="bg">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title') – PMS</title>
    <style>
        :root { --bg: #f5f5f4; --card: #fff; --text: #1c1917; --muted: #57534e; --line: #d6d3d1; --accent: #1d4ed8; --error: #b91c1c; --ok: #15803d; }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: flex; align-items: flex-start; justify-content: center;
               font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; background: var(--bg); color: var(--text); padding: 48px 16px; }
        main { width: 100%; max-width: 420px; background: var(--card); border: 1px solid var(--line); border-radius: 10px; padding: 28px; }
        main.wide { max-width: 640px; }
        h1 { font-size: 1.4rem; margin: 0 0 18px; }
        p { line-height: 1.5; }
        label { display: block; font-weight: 600; margin: 14px 0 6px; }
        input[type=email], input[type=password], input[type=text] { width: 100%; padding: 10px 12px; font-size: 1rem; border: 1px solid var(--line); border-radius: 6px; }
        button { margin-top: 18px; padding: 10px 16px; font-size: 1rem; border: 0; border-radius: 6px; background: var(--accent); color: #fff; cursor: pointer; }
        button.secondary { background: #e7e5e4; color: var(--text); }
        a { color: var(--accent); }
        .muted { color: var(--muted); font-size: .9rem; }
        .errors { color: var(--error); background: #fef2f2; border: 1px solid #fecaca; border-radius: 6px; padding: 10px 12px; margin-bottom: 12px; }
        .status { color: var(--ok); background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 6px; padding: 10px 12px; margin-bottom: 12px; }
        .row { display: flex; gap: 12px; align-items: center; justify-content: space-between; flex-wrap: wrap; }
        code, .codes { font-family: ui-monospace, Consolas, monospace; }
        .codes { columns: 2; padding-left: 18px; }
    </style>
</head>
<body>
<main class="@yield('width')">
    @if (session('status'))
        <div class="status">{{ session('status') }}</div>
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
</body>
</html>
