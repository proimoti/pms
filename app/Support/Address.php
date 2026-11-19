<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;

/** Адрес за показване от структурираните полета. */
final class Address
{
    public static function format(Model $model): string
    {
        $prefixed = fn (string $field) => $model->{$field} ? __('registry.abbr.'.$field).' '.$model->{$field} : null;

        $parts = array_filter([
            $model->postal_code && $model->city ? "{$model->postal_code} {$model->city}" : $model->city,
            $prefixed('district'),
            trim(($model->street ?? '').' '.($model->street_number ?? '')) ?: null,
            $prefixed('entrance'),
            $prefixed('floor'),
            $prefixed('apartment'),
        ]);

        return implode(', ', $parts);
    }
}
