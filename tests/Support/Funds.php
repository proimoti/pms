<?php

namespace Tests\Support;

use App\Models\Fund;
use App\Models\Property;
use App\Services\Utilities\FundService;

/** Измислен общ фонд за тестовете (PROJECT.md, „Общ фонд“, 22–23.11.2026). */
trait Funds
{
    private int $fundCounter = 0;

    /**
     * Фонд с дадените имоти; име и аналитика са различни при всяко извикване.
     *
     * @param  list<Property>  $properties
     */
    protected function fund(array $properties, array $overrides = []): Fund
    {
        $this->fundCounter++;

        return app(FundService::class)->create(array_merge([
            'name' => "Измислен вход {$this->fundCounter}",
            'analytic' => "ВХ-{$this->fundCounter}",
            'properties' => array_map(fn (Property $property) => $property->id, $properties),
        ], $overrides));
    }
}
