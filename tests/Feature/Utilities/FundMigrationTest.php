<?php

namespace Tests\Feature\Utilities;

use App\Models\Fund;
use App\Models\UtilityAccount;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Contracts\ContractTestCase;

/**
 * Старите фиксирани абонати получават фонд (PROJECT.md, „Общ фонд“, уточнено
 * 23.11.2026): миграцията създава по един фонд за всеки фиксиран абонат без
 * фонд, с неговите имоти и име като „Фонд: Такса вход“; без аналитика
 * (предупреждение „липсва аналитика“). Нищо не се губи; създаденото е в журнала.
 *
 * Абонатите „отпреди фонда“ се правят направо в базата с изключени проверки
 * (само за този тест), защото след миграцията базата не допуска фиксиран
 * абонат без фонд.
 */
class FundMigrationTest extends ContractTestCase
{
    private function oldAccount(string $billing, ?string $note, string $kind, array $properties): int
    {
        $companyId = $this->staff->company_id;
        $id = DB::table('utility_accounts')->insertGetId([
            'company_id' => $companyId, 'kind' => $kind, 'billing' => $billing,
            'fixed_amount' => $billing === 'fixed' ? '15.00' : null, 'provider_id' => null, 'number' => null,
            'period_start_day' => 1, 'valid_from' => '2026-01-01', 'note' => $note, 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach (array_values($properties) as $i => $property) {
            DB::table('utility_account_properties')->insert([
                'company_id' => $companyId, 'utility_account_id' => $id, 'property_id' => $property->id,
                'share' => count($properties) === 1 ? '100.00' : '50.00', 'position' => $i + 1, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $id;
    }

    private function backfill(): void
    {
        $migration = require database_path('migrations/2026_11_23_000002_add_funds_to_utility_accounts.php');
        $migration->backfill();
    }

    public function test_each_old_fixed_account_gets_its_own_fund_with_its_properties(): void
    {
        [$a, $b, $c] = [$this->property(), $this->property(), $this->property()];

        DB::statement('SET SESSION check_constraint_checks = 0');
        $entrance = $this->oldAccount('fixed', 'Такса вход', 'common', [$a, $b]);
        $sameNote = $this->oldAccount('fixed', 'Такса вход', 'common', [$c]);
        $noNote = $this->oldAccount('fixed', null, 'common', [$c]);
        DB::statement('SET SESSION check_constraint_checks = 1');

        $this->backfill();

        $first = Fund::query()->findOrFail(UtilityAccount::query()->findOrFail($entrance)->fund_id);
        $this->assertSame('Фонд: Такса вход', $first->name);
        $this->assertNull($first->analytic);
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $first->propertyIds());

        $second = Fund::query()->findOrFail(UtilityAccount::query()->findOrFail($sameNote)->fund_id);
        $this->assertSame('Фонд: Такса вход (2)', $second->name, 'имената са различни');
        $third = Fund::query()->findOrFail(UtilityAccount::query()->findOrFail($noNote)->fund_id);
        $this->assertSame('Фонд: '.__('utilities.kinds.common'), $third->name);

        $this->assertSame(3, Fund::query()->count());
        $this->assertSame($this->staff->company_id, $first->company_id);
        $this->assertDatabaseHas('audit_log', ['auditable_type' => 'funds', 'auditable_id' => $first->id, 'event' => 'created']);
        $this->assertDatabaseHas('audit_log', ['auditable_type' => 'utility_accounts', 'auditable_id' => $entrance, 'event' => 'updated']);
    }

    public function test_actual_accounts_and_accounts_with_a_fund_are_left_alone(): void
    {
        $property = $this->property();
        $actual = $this->oldAccount('actual', null, 'electricity', [$property]);

        $this->backfill();
        $this->backfill(); // втори път не прави нищо

        $this->assertNull(UtilityAccount::query()->findOrFail($actual)->fund_id);
        $this->assertSame(0, Fund::query()->count());
    }
}
