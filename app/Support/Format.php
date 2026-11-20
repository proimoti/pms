<?php

namespace App\Support;

use Carbon\CarbonInterface;

/** Показване на дати, суми и проценти по български: 31.12.2026, 1 200,00, 12,50 %. */
final class Format
{
    public static function date(?CarbonInterface $date): string
    {
        return $date === null ? __('registry.none') : $date->format('d.m.Y');
    }

    public static function money(?string $amount): string
    {
        return $amount === null ? __('registry.none') : number_format((float) $amount, 2, ',', ' ');
    }

    public static function percent(?string $percent): string
    {
        return $percent === null ? __('registry.none') : number_format((float) $percent, 2, ',', ' ').' %';
    }
}
