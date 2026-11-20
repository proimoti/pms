<?php

namespace App\Services\Charges;

/** Едно предупреждение в работната таблица: какво да се провери и къде. */
final class WorksheetWarning
{
    public function __construct(
        public readonly string $code,
        public readonly string $message,
        public readonly ?int $chargeDraftId = null,
        public readonly ?int $leaseContractId = null,
        public readonly ?int $propertyId = null,
    ) {}
}
