{{-- Отговорът след запис в малкия прозорец „+ Нов“: казва на формата на
     договора кой е новият запис. Само към същия сайт (origin). --}}
<!DOCTYPE html>
<html lang="bg">
<head>
    <meta charset="utf-8">
    <title>{{ __('registry.saved') }}</title>
</head>
<body>
<p>{{ __('registry.saved') }}</p>
<script>
    {{-- JSON_HEX_*: знаците < > & ' " в името не могат да излязат от скрипта. --}}
    window.parent.postMessage({!! json_encode(['type' => 'pms-quick-new', 'section' => $section, 'id' => $id, 'label' => $label],
        JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}, window.location.origin);
</script>
</body>
</html>
