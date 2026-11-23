<?php

namespace Tests\Feature\Utilities;

use App\Models\Fund;
use App\Models\FundProperty;
use App\Models\PaidUtilityBill;
use App\Models\Provider;
use App\Models\User;
use App\Models\UserProperty;
use App\Models\UtilityAccount;
use App\Services\Utilities\FundService;
use App\Services\Utilities\UtilityAccountService;
use App\Services\Utilities\UtilityImporter;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;
use Tests\Feature\Contracts\ContractTestCase;
use Tests\Support\Funds;
use Tests\Support\UtilityFiles;

/**
 * Регистър „Общ фонд“ и връзката на абонатите с него (PROJECT.md, „Общ фонд“,
 * решено 22.11.2026, уточнено 23.11.2026): име, аналитика за Микроинвест и
 * имотите (един или няколко); архив вместо триене; журнал. Всеки фиксиран
 * абонат е към фонд; реален абонат може да е „плаща се от фонд“. Имотите на
 * абоната са сред имотите на фонда. Само измислени имена и номера.
 */
class FundTest extends ContractTestCase
{
    use Funds, UtilityFiles;

    private FundService $funds;

    private UtilityAccountService $accounts;

    private Provider $power;

    protected function setUp(): void
    {
        parent::setUp();
        $this->funds = app(FundService::class);
        $this->accounts = app(UtilityAccountService::class);
        $this->power = Provider::query()->create(['name' => 'Измислено Електро', 'easypay_name' => 'IZM EL', 'epay_name' => 'Измислено Електро АД']);
    }

    /** Грешките (по поле) при опит за запис. */
    private function errorsOf(callable $save): array
    {
        try {
            $save();
        } catch (ValidationException $e) {
            return $e->errors();
        }

        $this->fail('Записът е приет, а трябваше да е отказан.');
    }

    /** Фиксиран абонат „такса вход“ 15 € към фонда. */
    private function fixedData(Fund $fund, array $properties, array $overrides = []): array
    {
        return array_merge([
            'kind' => 'common', 'billing' => 'fixed', 'fixed_amount' => '15', 'valid_from' => '2026-01-01',
            'fund_id' => $fund->id, 'note' => 'Такса вход',
            'properties' => array_map(fn ($p) => ['property_id' => $p->id], $properties),
        ], $overrides);
    }

    /** Реален абонат за общия ток на входа. */
    private function actualData(array $properties, array $overrides = []): array
    {
        return array_merge([
            'provider_id' => $this->power->id, 'number' => '0077001', 'kind' => 'electricity', 'billing' => 'actual',
            'valid_from' => '2026-01-01', 'properties' => array_map(fn ($p) => ['property_id' => $p->id], $properties),
        ], $overrides);
    }

    // Регистърът

    public function test_fund_has_a_name_an_analytic_and_its_properties(): void
    {
        [$a, $b, $c] = [$this->property(), $this->property(), $this->property()];

        $fund = $this->funds->create(['name' => 'Вход А (измислен)', 'analytic' => 'ВХ-А', 'note' => 'Общите разходи на вход А', 'properties' => [$a->id, $b->id, $c->id]]);

        $this->assertSame('Вход А (измислен)', $fund->name);
        $this->assertSame('ВХ-А', $fund->analytic);
        $this->assertSame($this->staff->company_id, $fund->company_id);
        $this->assertEqualsCanonicalizing([$a->id, $b->id, $c->id], $fund->propertyIds());
        $this->assertFalse($fund->missingAnalytic());
    }

    public function test_fund_may_be_for_a_single_property(): void
    {
        $property = $this->property();

        $this->assertSame([$property->id], $this->fund([$property])->propertyIds());
    }

    public function test_name_analytic_and_a_property_are_required_and_errors_come_together(): void
    {
        $errors = $this->errorsOf(fn () => $this->funds->create(['name' => ' ', 'analytic' => '', 'properties' => []]));

        $this->assertEqualsCanonicalizing(['name', 'analytic', 'properties'], array_keys($errors));
        $this->assertSame(0, Fund::query()->count());
    }

    public function test_name_and_analytic_are_unique_in_the_company(): void
    {
        $this->fund([$this->property()], ['name' => 'Вход Б', 'analytic' => 'ВХ-Б']);

        $errors = $this->errorsOf(fn () => $this->fund([$this->property()], ['name' => 'Вход Б', 'analytic' => 'ВХ-Б']));
        $this->assertEqualsCanonicalizing(['name', 'analytic'], array_keys($errors));

        // Друга фирма може да ползва същите.
        $other = $this->inOtherCompany(fn () => $this->fund([$this->property()], ['name' => 'Вход Б', 'analytic' => 'ВХ-Б']));
        $this->assertNotNull($other->id);
    }

    public function test_archived_or_foreign_property_is_rejected(): void
    {
        $archived = $this->property();
        $archived->archive();
        $foreign = $this->inOtherCompany(fn () => $this->property());

        $this->assertArrayHasKey('properties', $this->errorsOf(fn () => $this->fund([$archived])));
        $this->assertArrayHasKey('properties', $this->errorsOf(fn () => $this->fund([$foreign])));
    }

    public function test_update_replaces_name_and_properties(): void
    {
        [$a, $b] = [$this->property(), $this->property()];
        $fund = $this->fund([$a]);

        $this->funds->update($fund, ['name' => 'Вход В', 'analytic' => 'ВХ-В', 'properties' => [$a->id, $b->id]]);

        $this->assertSame('Вход В', $fund->fresh()->name);
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $fund->fresh()->propertyIds());
    }

    public function test_fund_is_never_deleted_only_archived(): void
    {
        $fund = $this->fund([$this->property()]);

        try {
            $fund->delete();
            $this->fail('Изтриването трябваше да бъде отказано.');
        } catch (LogicException) {
            $this->assertDatabaseHas('funds', ['id' => $fund->id]);
        }

        $fund->archive();
        $this->assertTrue($fund->fresh()->isArchived());
    }

    public function test_fund_with_an_active_account_cannot_be_archived(): void
    {
        $property = $this->property();
        $fund = $this->fund([$property]);
        $account = $this->accounts->create($this->fixedData($fund, [$property]));

        $this->assertArrayHasKey('fund', $this->errorsOf(fn () => $fund->archive()));
        $this->assertFalse($fund->fresh()->isArchived());

        $account->archive();
        $fund->archive();
        $this->assertTrue($fund->fresh()->isArchived());
    }

    public function test_property_of_an_account_cannot_leave_the_fund(): void
    {
        [$a, $b] = [$this->property(), $this->property()];
        $fund = $this->fund([$a, $b]);
        $this->accounts->create($this->fixedData($fund, [$b]));

        $errors = $this->errorsOf(fn () => $this->funds->update($fund, ['name' => $fund->name, 'analytic' => $fund->analytic, 'properties' => [$a->id]]));

        $this->assertArrayHasKey('properties', $errors);
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $fund->fresh()->propertyIds());
    }

    public function test_changes_go_to_the_audit_log(): void
    {
        [$a, $b] = [$this->property(), $this->property()];
        $fund = $this->fund([$a]);
        $this->funds->update($fund, ['name' => 'Вход Г', 'analytic' => 'ВХ-Г', 'properties' => [$b->id]]);
        $fund->archive();

        foreach (['created', 'updated', 'archived'] as $event) {
            $this->assertDatabaseHas('audit_log', ['auditable_type' => 'funds', 'auditable_id' => $fund->id, 'event' => $event]);
        }
        $this->assertDatabaseHas('audit_log', ['auditable_type' => 'fund_properties', 'event' => 'created']);
        $this->assertDatabaseHas('audit_log', ['auditable_type' => 'fund_properties', 'event' => 'deleted']);
    }

    // Достъп и фирми

    public function test_read_only_staff_cannot_save(): void
    {
        $property = $this->property();
        $this->actingAs(User::factory()->create(['company_id' => $this->staff->company_id, 'access_level' => 'read']));

        $this->expectException(AuthorizationException::class);
        $this->fund([$property]);
    }

    public function test_assigned_only_staff_sees_funds_with_at_least_one_of_their_properties(): void
    {
        [$mine, $notMine, $other] = [$this->property(), $this->property(), $this->property()];
        $shared = $this->fund([$notMine, $mine]);
        $this->fund([$other]);

        $assigned = User::factory()->assignedPropertiesOnly()->create(['company_id' => $this->staff->company_id]);
        UserProperty::query()->create(['user_id' => $assigned->id, 'property_id' => $mine->id]);
        $this->actingAs($assigned);

        $this->assertSame([$shared->id], Fund::query()->pluck('id')->all());
        $this->assertCount(2, $shared->properties()->get(), 'целият фонд, с всички имоти');
    }

    public function test_company_a_does_not_see_funds_of_company_b(): void
    {
        $foreign = $this->inOtherCompany(fn () => $this->fund([$this->property()]));

        $this->assertSame(0, Fund::query()->count());
        $this->assertSame(0, FundProperty::query()->count());
        $this->assertNull(Fund::query()->find($foreign->id));

        $this->actingAs($this->otherStaff);
        $this->assertSame([$foreign->id], Fund::query()->pluck('id')->all());
    }

    public function test_fund_of_company_b_cannot_be_chosen_for_an_account(): void
    {
        $property = $this->property();
        $foreign = $this->inOtherCompany(fn () => $this->fund([$this->property()]));

        $this->assertArrayHasKey('fund_id', $this->errorsOf(fn () => $this->accounts->create($this->fixedData($foreign, [$property]))));
    }

    // Абонатите

    public function test_fixed_account_needs_a_fund(): void
    {
        $property = $this->property();

        $errors = $this->errorsOf(fn () => $this->accounts->create([
            'kind' => 'common', 'billing' => 'fixed', 'fixed_amount' => '15', 'valid_from' => '2026-01-01',
            'properties' => [['property_id' => $property->id]],
        ]));

        $this->assertArrayHasKey('fund_id', $errors);
    }

    public function test_fixed_account_belongs_to_its_fund(): void
    {
        $property = $this->property();
        $fund = $this->fund([$property]);

        $account = $this->accounts->create($this->fixedData($fund, [$property]));

        $this->assertSame($fund->id, $account->fund_id);
        $this->assertFalse($account->paid_from_fund);
        $this->assertSame([$account->id], $fund->accounts()->pluck('id')->all());
    }

    public function test_account_properties_must_be_among_the_fund_properties(): void
    {
        [$in, $out] = [$this->property(), $this->property()];
        $fund = $this->fund([$in]);

        $errors = $this->errorsOf(fn () => $this->accounts->create($this->fixedData($fund, [$in, $out], [
            'properties' => [['property_id' => $in->id, 'share' => '50'], ['property_id' => $out->id, 'share' => '50']],
        ])));

        $this->assertArrayHasKey('properties', $errors);
    }

    public function test_archived_fund_cannot_be_chosen(): void
    {
        $property = $this->property();
        $fund = $this->fund([$property]);
        $fund->archive();

        $this->assertArrayHasKey('fund_id', $this->errorsOf(fn () => $this->accounts->create($this->fixedData($fund, [$property]))));
    }

    public function test_actual_account_may_be_paid_from_a_fund_without_shares(): void
    {
        [$a, $b, $c] = [$this->property(), $this->property(), $this->property()];
        $fund = $this->fund([$a, $b, $c]);

        // Сметката не се дели: дяловете не се попълват (и не се пазят, ако са подадени).
        $account = $this->accounts->create($this->actualData([$a, $b, $c], [
            'paid_from_fund' => '1', 'fund_id' => $fund->id,
            'properties' => [['property_id' => $a->id], ['property_id' => $b->id, 'share' => '40'], ['property_id' => $c->id]],
        ]));

        $this->assertTrue($account->paid_from_fund);
        $this->assertSame($fund->id, $account->fund_id);
        $this->assertSame([null, null, null], $account->properties()->pluck('share')->all());
    }

    public function test_paid_from_fund_needs_a_fund(): void
    {
        $property = $this->property();

        $errors = $this->errorsOf(fn () => $this->accounts->create($this->actualData([$property], ['paid_from_fund' => '1'])));

        $this->assertArrayHasKey('fund_id', $errors);
    }

    public function test_actual_account_not_paid_from_fund_keeps_no_fund(): void
    {
        $property = $this->property();
        $fund = $this->fund([$property]);

        $account = $this->accounts->create($this->actualData([$property], ['fund_id' => $fund->id]));

        $this->assertFalse($account->paid_from_fund);
        $this->assertNull($account->fund_id);
    }

    public function test_shares_are_still_required_for_a_shared_account_not_paid_from_fund(): void
    {
        [$a, $b] = [$this->property(), $this->property()];

        $this->assertArrayHasKey('properties', $this->errorsOf(fn () => $this->accounts->create($this->actualData([$a, $b]))));
    }

    public function test_paid_from_fund_and_the_fund_are_locked_after_the_first_bill(): void
    {
        $property = $this->property();
        [$fund, $other] = [$this->fund([$property]), $this->fund([$property])];
        $account = $this->accounts->create($this->actualData([$property], ['paid_from_fund' => '1', 'fund_id' => $fund->id]));
        app(UtilityImporter::class)->import($this->easypayFile([
            ['ref' => 910001, 'date' => '2026-12-05 10:00:00', 'number' => 77001, 'provider' => 'IZM EL', 'amount' => 30],
        ]), 'vhod.xlsx');
        $this->assertSame(1, PaidUtilityBill::query()->count());

        $this->assertArrayHasKey('paid_from_fund', $this->errorsOf(fn () => $this->accounts->update($account, $this->actualData([$property]))));
        $this->assertArrayHasKey('fund_id', $this->errorsOf(fn () => $this->accounts->update($account, $this->actualData([$property], ['paid_from_fund' => '1', 'fund_id' => $other->id]))));
    }

    public function test_fixed_account_may_move_to_another_fund(): void
    {
        $property = $this->property();
        [$fund, $other] = [$this->fund([$property]), $this->fund([$property])];
        $account = $this->accounts->create($this->fixedData($fund, [$property]));

        $this->accounts->update($account, $this->fixedData($other, [$property]));

        $this->assertSame($other->id, $account->fresh()->fund_id);
    }

    public function test_database_rejects_a_fixed_account_without_fund_and_a_fund_on_an_ordinary_account(): void
    {
        [$a, $b] = [$this->property(), $this->property()];
        $fund = $this->fund([$a, $b]);
        $fixed = $this->accounts->create($this->fixedData($fund, [$a]));
        $actual = $this->accounts->create($this->actualData([$b]));

        $failed = 0;
        foreach ([
            fn () => DB::table('utility_accounts')->where('id', $fixed->id)->update(['fund_id' => null]),
            fn () => DB::table('utility_accounts')->where('id', $fixed->id)->update(['paid_from_fund' => true]),
            fn () => DB::table('utility_accounts')->where('id', $actual->id)->update(['fund_id' => $fund->id]),
            fn () => DB::table('utility_accounts')->where('id', $actual->id)->update(['paid_from_fund' => true]),
        ] as $change) {
            try {
                DB::transaction($change);
            } catch (QueryException) {
                $failed++;
            }
        }

        $this->assertSame(4, $failed);
    }
}
