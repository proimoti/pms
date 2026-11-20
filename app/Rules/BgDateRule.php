<?php

namespace App\Rules;

use App\Support\BgDate;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** Вярна дата във вида дд.мм.гггг (или вътрешния гггг-мм-дд). Празното се пропуска. */
class BgDateRule implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        if (! is_string($value) || BgDate::parse($value) === null) {
            $fail('validation.bg_date')->translate();
        }
    }
}
