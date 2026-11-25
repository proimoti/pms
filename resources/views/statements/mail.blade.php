<!DOCTYPE html>
<html lang="bg">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"></head>
<body style="margin: 0; padding: 16px; background: #ffffff;">
    {{-- Писмото с известието (24.11.2026): кратък текст и самото известие; логото е вградено (cid:). --}}
    <p style="font-family: Arial, sans-serif; font-size: 14px; color: #111827; margin: 0 0 16px;">
        {{ __('statements.send.intro', ['number' => $statement->number, 'month' => mb_strtolower(\App\Services\Charges\Month::label($statement->month))]) }}
    </p>
    @php($logo = $logoData ? $message->embedData($logoData['bytes'], 'logo.'.($logoData['mime'] === 'image/png' ? 'png' : 'jpg'), $logoData['mime']) : null)
    {{-- Логото в писмото: до 200 × 60 px, с пропорциите (25.11.2026). --}}
    @php($size = $logoData ? @getimagesizefromstring($logoData['bytes']) : false)
    @php($scale = $size ? min(1, 200 / max(1, $size[0]), 60 / max(1, $size[1])) : 1)
    @php($logoSize = $size ? [max(1, (int) round($size[0] * $scale)), max(1, (int) round($size[1] * $scale))] : null)
    @include('statements.document', ['statement' => $statement, 'logo' => $logo, 'logoSize' => $logoSize])
</body>
</html>
