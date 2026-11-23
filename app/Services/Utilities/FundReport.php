<?php

namespace App\Services\Utilities;

/** Справката за общ фонд: събрано, платено и остатък (суми „37.50“; остатъкът може да е под 0). */
final class FundReport
{
    public function __construct(
        public readonly string $collected,
        public readonly string $paid,
        public readonly string $left,
    ) {}
}
