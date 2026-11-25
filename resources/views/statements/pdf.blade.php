<!DOCTYPE html>
<html lang="bg">
<head>
    <meta charset="utf-8">
    <title>{{ __('charges.statements.notice') }} № {{ $statement->number }}</title>
    <style>
        @page { margin: 18mm 15mm; }
        body { font-family: 'DejaVu Sans', sans-serif; margin: 0; }
    </style>
</head>
<body>
    @include('statements.document', ['statement' => $statement, 'logo' => $logo ?? null])
</body>
</html>
