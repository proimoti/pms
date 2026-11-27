<?php

namespace App\Services\Export;

use App\Enums\OwnerContractType;
use App\Enums\UtilityKind;
use App\Models\ExportRule;
use App\Models\UtilityAccount;

/**
 * Настройките за експорта към Микроинвест (PROJECT.md, „Експорт към
 * Микроинвест“, 26.11.2026). Всички стойности ги въвежда администраторът на
 * фирмата; тук няма номера на сметки и начални стойности.
 *
 * Видове настройки:
 * - synthetic: само синтетична сметка (3 цифри); аналитиките се добавят при
 *   експорта (418/договор/имот, 408/договор/имот, 407/вид/имот, фонд/аналитика…);
 * - account: сметка с аналитиката си, например 703/1;
 * - code: код на вида услуга (средната аналитика на 407/вид/имот);
 * - flag: да/не.
 */
final class ExportSettings
{
    /** @return array<string, string> ключ => вид */
    public static function keys(): array
    {
        $keys = [
            'tenant' => 'synthetic', 'deposit' => 'synthetic', 'owner' => 'synthetic', 'utility' => 'synthetic', 'fund' => 'synthetic',
        ];
        foreach (OwnerContractType::cases() as $type) {
            $keys['revenue_'.$type->value] = 'account';
        }
        $keys += ['revenue_penalty' => 'account', 'revenue_other' => 'account', 'manager_cost' => 'account', 'deposit_income' => 'account',
            'include_paid_bills' => 'flag', 'bank_epay' => 'account', 'bank_easypay' => 'account'];
        foreach (UtilityKind::cases() as $kind) {
            $keys['kind_'.$kind->value] = 'code';
        }

        return $keys;
    }

    /** @return array<string, string> правилото за проверка на всеки вид */
    public static function patterns(): array
    {
        return [
            'synthetic' => '/^\d{3}$/',
            'account' => '/^\d{3}(\/[0-9A-Za-zА-Яа-я]+)*$/u',
            'code' => '/^[0-9A-Za-zА-Яа-я]+$/u',
            'flag' => '/^[01]$/',
        ];
    }

    /** @return array<string, ?string> */
    public function all(): array
    {
        // Всеки път от базата: услугата може да живее по-дълго от една заявка.
        return ExportRule::query()->pluck('value', 'key')->all();
    }

    public function get(string $key): ?string
    {
        $value = $this->all()[$key] ?? null;

        return $value === null || $value === '' ? null : $value;
    }

    public function includePaidBills(): bool
    {
        return $this->get('include_paid_bills') === '1';
    }

    public function kindCode(string $kind): ?string
    {
        return $this->get('kind_'.$kind);
    }

    /** Записва стойностите (празно = няма); само променените отиват в журнала. */
    public function save(array $values): void
    {
        foreach (array_keys(self::keys()) as $key) {
            if (! array_key_exists($key, $values)) {
                continue;
            }
            $value = trim((string) $values[$key]);
            $rule = ExportRule::query()->firstOrNew(['key' => $key]);
            $rule->value = $value === '' ? null : $value;
            if (! $rule->exists && $rule->value === null) {
                continue;
            }
            $rule->save();
        }
    }

    /**
     * Липсващите настройки, без които експортът отказва: всички сметки; банките —
     * само ако платените сметки се експортират; кодовете — за видовете, които
     * фирмата ползва при абонати с един имот (общите са с абонатния номер).
     *
     * @return list<string>
     */
    public function missing(): array
    {
        $kindsInUse = UtilityAccount::query()->whereNull('fund_id')->withCount('properties')->get()
            ->filter(fn (UtilityAccount $account) => $account->properties_count === 1)
            ->map(fn (UtilityAccount $account) => $account->kind->value)->unique()->all();

        $missing = [];
        foreach (self::keys() as $key => $type) {
            $required = match (true) {
                $type === 'flag' => false,
                in_array($key, ['bank_epay', 'bank_easypay'], true) => $this->includePaidBills(),
                // Сметката за приход от депозит — само ако фирмата има такъв приход (26.11.2026).
                $key === 'deposit_income' => \App\Models\LedgerEntry::query()->withoutGlobalScopes()->where('company_id', \App\Models\Scopes\CompanyScope::restrictedCompanyId())
                    ->where('kind', \App\Enums\LedgerKind::DepositIncome->value)->exists(),
                $type === 'code' => in_array(substr($key, 5), $kindsInUse, true),
                default => true,
            };
            if ($required && $this->get($key) === null) {
                $missing[] = $key;
            }
        }

        return $missing;
    }
}
