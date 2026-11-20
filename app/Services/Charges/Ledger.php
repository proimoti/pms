<?php

namespace App\Services\Charges;

use App\Enums\LedgerKind;
use App\Models\LedgerEntry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Записът в книгата и заключването на фирмата при издаване и корекции.
 *
 * Издаването и корекциите на една фирма вървят едно след друго: първо се
 * заключва редът на фирмата в базата (до края на транзакцията). Така двойно
 * натискане или двама служители едновременно не дават двойни записи: вторият
 * чака, после вижда, че вече е издадено, и се отказва.
 */
final class Ledger
{
    /** Заключва фирмата до края на текущата транзакция. */
    public static function lockCompany(int $companyId): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Заключването на фирмата е само в транзакция.');
        }

        DB::table('companies')->where('id', $companyId)->lockForUpdate()->first();
    }

    /** Нов запис (сумата в центове, със знак; 0 не се записва). */
    public static function book(CarbonImmutable $month, LedgerKind $kind, array $attributes, int $cents): ?LedgerEntry
    {
        if ($cents === 0) {
            return null;
        }

        $entry = new LedgerEntry;
        $entry->forceFill(array_merge($attributes, [
            'month' => $month,
            'entry_date' => CarbonImmutable::today(),
            'kind' => $kind,
            'amount' => Cents::format($cents),
            'created_by' => Auth::id(),
            'created_at' => now(),
        ]))->save();

        return $entry;
    }
}
