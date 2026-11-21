{{-- Общ елемент „хармоника“ (21.11.2026): ред, който се разгъва с клик и показва
     подробностите. Вграденото в браузъра разгъване (details/summary): работи без
     скрипт и с клавиатурата. Слага се в <div class="accordion-list">.
     state: ok (зелена лента), warning (жълта), off (сива); anchor: id за връзка към реда.
     Слотове: title, sub, note (предупреждение, жълто), meta, amount — всички освен
     title по избор; съдържанието са подробностите. --}}
@props(['state' => 'ok', 'open' => false, 'anchor' => null])
<details class="accordion state-{{ $state }}"@if ($anchor) id="{{ $anchor }}"@endif @if ($open) open @endif>
    <summary>
        <div>
            <div class="acc-title">{{ $title }}</div>
            @isset($sub)<div class="acc-sub">{{ $sub }}</div>@endisset
            @isset($note)<div class="acc-sub warn">{{ $note }}</div>@endisset
        </div>
        <div class="acc-meta">{{ $meta ?? '' }}</div>
        <div class="acc-amount">{{ $amount ?? '' }}</div>
    </summary>
    <div class="acc-body">{{ $slot }}</div>
</details>
