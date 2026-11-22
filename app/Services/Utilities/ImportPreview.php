<?php

namespace App\Services\Utilities;

/** Какво ще стане при „Внеси“: нищо от това още не е записано. */
final class ImportPreview
{
    /**
     * @param  list<PreviewBill>  $bills
     * @param  list<ImportRow>  $duplicates  вече внесени (или вече „за решение“/игнорирани)
     * @param  list<PreviewIssue>  $issues
     */
    public function __construct(
        public readonly string $source,
        public readonly int $rowsCount,
        public readonly array $bills,
        public readonly array $duplicates,
        public readonly array $issues,
        public readonly int $skippedFees,
    ) {}
}
