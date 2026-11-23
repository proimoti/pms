<?php

namespace App\Services\Utilities;

use App\Enums\LedgerAccount;
use App\Models\LedgerEntry;
use App\Models\Scopes\CompanyScope;
use App\Models\Scopes\RegistryVisibilityScope;
use App\Services\Charges\Cents;
use LogicException;

/**
 * Салдата по 407 на имотите (платено +, начислено на наемател −), от книгата.
 * Записите на общ фонд (407 на фонда, без имот) не се броят (PROJECT.md,
 * „Общ фонд“, уточнено 23.11.2026). Основа за „Неразпределени битови
 * сметки“ (част 5).
 */
final class UtilityBalances
{
    /** @return array<int, string> имот → салдо („48.20“), по номера на имота в базата */
    public function byProperty(int $companyId): array
    {
        $restricted = CompanyScope::restrictedCompanyId();
        if ($restricted !== null && $restricted !== $companyId) {
            throw new LogicException('Салдата са на друга фирма.');
        }

        return LedgerEntry::query()->withoutGlobalScope(RegistryVisibilityScope::class)
            ->where('company_id', $companyId)
            ->where('account', LedgerAccount::Utility->value)
            ->whereNull('fund_id')
            ->whereNotNull('property_id')
            ->groupBy('property_id')->orderBy('property_id')
            ->selectRaw('property_id, SUM(amount) as balance')
            ->get()
            ->mapWithKeys(fn ($row) => [(int) $row->property_id => Cents::format(Cents::from((string) $row->balance))])
            ->all();
    }
}
