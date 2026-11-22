<?php

namespace App\Services\Utilities;

use Carbon\CarbonImmutable;

/**
 * Един ред от файла за внос, вече разчетен. kind: bill (платена сметка или
 * нещо, което прилича на такава), income (приход в ePay, пропуска се тихо),
 * fee (банкова такса в ePay: разход без номер в скоби, само се брои).
 */
final class ImportRow
{
    public function __construct(
        public readonly int $line,
        public readonly string $kind,
        public readonly ?string $reference,
        public readonly ?CarbonImmutable $paidAt,
        public readonly ?string $providerName,
        public readonly ?string $number,
        public readonly ?int $amountCents,
        public readonly ?string $description = null,
        public readonly ?string $details = null,
    ) {}
}
