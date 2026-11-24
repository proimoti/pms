<?php

namespace App\Models\Concerns;

/**
 * Номер във фирмата, като HasCompanyNumber, но следващият е най-малкият
 * свободен (бенефициенти и отговорни лица, PROJECT.md т. 2.4 и 2.7,
 * 24.11.2026). Заети са номерата на всички записи, и архивираните.
 */
trait HasSmallestFreeNumber
{
    use HasCompanyNumber;

    protected function nextNumber(): int
    {
        $taken = $this->sameCompanyQuery()->pluck('number')->map(fn ($number) => (int) $number)->flip();
        $next = 1;
        while ($taken->has($next)) {
            $next++;
        }

        return $next;
    }
}
