<?php

namespace App\Services\Utilities;

/**
 * Ред „за решение“ и причината: unknown_provider, unknown_number,
 * fixed_account, inactive_account, bad_amount, bad_date, no_reference.
 */
final class PreviewIssue
{
    public function __construct(
        public readonly ImportRow $row,
        public readonly string $reason,
        public readonly ?int $providerId,
    ) {}
}
