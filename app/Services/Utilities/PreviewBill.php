<?php

namespace App\Services\Utilities;

use App\Models\UtilityAccount;

/** Разпознат ред: абонатът и частите по имоти (property_id => сума „33.00“; остатъкът е в последната). */
final class PreviewBill
{
    /** @param array<int, string> $parts */
    public function __construct(
        public readonly ImportRow $row,
        public readonly UtilityAccount $account,
        public readonly array $parts,
        public readonly array $shares,
    ) {}
}
