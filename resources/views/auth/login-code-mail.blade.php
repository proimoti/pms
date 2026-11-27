<!DOCTYPE html>
<html lang="bg">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"></head>
<body style="margin: 0; padding: 16px; background: #ffffff; font-family: Arial, sans-serif; font-size: 14px; color: #111827;">
    {{-- Код за вход в портала (27.11.2026): от името на фирмата, с логото ѝ (cid:). --}}
    @if ($logoData)
        @php($size = @getimagesizefromstring($logoData['bytes']))
        @php($scale = $size ? min(1, 200 / max(1, $size[0]), 60 / max(1, $size[1])) : 1)
        <p style="margin: 0 0 16px;">
            <img src="{{ $message->embedData($logoData['bytes'], 'logo.'.($logoData['mime'] === 'image/png' ? 'png' : 'jpg'), $logoData['mime']) }}"
                alt="{{ $companyName }}" @if ($size) width="{{ max(1, (int) round($size[0] * $scale)) }}" height="{{ max(1, (int) round($size[1] * $scale)) }}" @endif>
        </p>
    @else
        <p style="margin: 0 0 16px; font-size: 18px; font-weight: bold;">{{ $companyName }}</p>
    @endif

    <p style="margin: 0 0 12px;">{{ __('portal.code.mail.intro') }}</p>
    <p style="margin: 0 0 16px; font-size: 28px; font-weight: bold; letter-spacing: 4px;">{{ $code }}</p>
    <p style="margin: 0 0 12px; color: #4b5563;">{{ __('portal.code.mail.expires', ['minutes' => $minutes]) }}</p>
    <p style="margin: 0 0 12px; color: #4b5563;">{{ __('portal.code.mail.not_you') }}</p>
    <p style="margin: 16px 0 0;">{{ $companyName }}</p>
</body>
</html>
