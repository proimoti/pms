<!DOCTYPE html>
<html lang="bg">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    @php($company = \App\Models\Company::query()->withoutGlobalScopes()->find(auth()->user()->company_id))
    <title>@yield('title') – {{ $company?->name }}</title>
    {{-- Порталът (27.11.2026): първо за телефон, с бранда на фирмата, без менюто на служителите. --}}
    <style>
        :root { --text: #111827; --muted: #6b7280; --line: #e5e7eb; --bg: #f3f4f6; --card: #ffffff; --accent: #1d4ed8; }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: system-ui, -apple-system, "Segoe UI", Arial, sans-serif; font-size: 16px; color: var(--text); background: var(--bg); }
        header.portal-head { background: var(--card); border-bottom: 1px solid var(--line); padding: 12px 16px; display: flex; align-items: center; justify-content: space-between; gap: 12px; }
        header.portal-head .brand { display: flex; align-items: center; gap: 10px; font-weight: 600; min-width: 0; }
        header.portal-head .brand img { max-height: 40px; max-width: 160px; }
        header.portal-head form { margin: 0; }
        header.portal-head button { background: none; border: 1px solid var(--line); border-radius: 6px; padding: 8px 12px; font-size: 15px; color: var(--text); cursor: pointer; }
        main { max-width: 720px; margin: 0 auto; padding: 16px; }
        h1 { font-size: 22px; margin: 4px 0 12px; }
        h2 { font-size: 18px; margin: 20px 0 8px; }
        .card { background: var(--card); border: 1px solid var(--line); border-radius: 10px; padding: 14px 16px; margin-bottom: 12px; }
        .muted { color: var(--muted); }
        .status { background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 6px; padding: 10px 12px; margin-bottom: 12px; }
    </style>
</head>
<body>
<header class="portal-head">
    <div class="brand">
        @if ($company?->logo_path)<img src="/portal/logo" alt="{{ $company->name }}">@endif
        <span>{{ $company?->name }}</span>
    </div>
    <form method="POST" action="{{ route('logout') }}">@csrf<button type="submit">{{ __('login.logout') }}</button></form>
</header>
<main>
    @if (session('status') && is_string(session('status')))
        <div class="status">{{ session('status') }}</div>
    @endif
    @yield('content')
</main>
</body>
</html>
