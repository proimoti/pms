<?php

namespace Tests\Feature\Utilities;

use App\Enums\UtilityBilling;
use App\Enums\UtilityKind;
use App\Models\Property;
use App\Models\Provider;
use App\Models\User;
use App\Models\UserProperty;
use App\Models\UtilityAccount;
use App\Services\Utilities\UtilityAccountService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Contracts\ContractTestCase;

/**
 * Абонати (PROJECT.md, т. 3.4 „Битови сметки, уточнения“ и т. 5.3, 21.11.2026):
 * доставчик, номер, вид, реален или фиксиран, период на потребление, имот или
 * имоти със съотношение и дати на действие. Номерът се пази точно, с нулите
 * отпред; уникален е по доставчик + номер, без значение от нулите отпред.
 * Само измислени номера.
 */
class UtilityAccountTest extends ContractTestCase
{
    private UtilityAccountService $accounts;

    private Provider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->accounts = app(UtilityAccountService::class);
        $this->provider = Provider::query()->create(['name' => 'Измислено Електро', 'easypay_name' => 'IZM EL', 'epay_name' => 'Измислено Електро АД']);
    }

    /** Реален абонат за ток с един имот. */
    private function data(array $properties, array $overrides = []): array
    {
        return array_merge([
            'provider_id' => $this->provider->id,
            'number' => '0012345',
            'kind' => 'electricity',
            'billing' => 'actual',
            'valid_from' => '2026-01-01',
            'properties' => array_map(fn ($p) => is_array($p) ? $p : ['property_id' => $p->id], $properties),
        ], $overrides);
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

    // Основни полета

    public function test_actual_account_with_one_property(): void
    {
        $property = $this->property();

        $account = $this->accounts->create($this->data([$property]));

        $this->assertSame('0012345', $account->number, 'номерът се пази точно, с нулите отпред');
        $this->assertSame(UtilityKind::Electricity, $account->kind);
        $this->assertSame(UtilityBilling::Actual, $account->billing);
        $this->assertNull($account->fixed_amount);
        $this->assertSame(1, $account->period_start_day);
        $this->assertNull($account->period_end_day, 'празно = последният ден на месеца');
        $this->assertSame('2026-01-01', $account->valid_from->toDateString());
        $this->assertNull($account->valid_to);
        $this->assertSame([[$property->id, '100.00']], $account->properties()->get()->map(fn ($p) => [$p->property_id, $p->share])->all());
    }

    public function test_all_kinds_exist(): void
    {
        $this->assertSame(
            ['electricity', 'water', 'heating', 'gas', 'internet', 'common', 'other'],
            array_map(fn (UtilityKind $kind) => $kind->value, UtilityKind::cases()),
        );
    }

    public function test_fixed_account_needs_an_amount_but_no_number_or_provider(): void
    {
        $property = $this->property();

        $account = $this->accounts->create($this->data([$property], [
            'provider_id' => null, 'number' => null, 'kind' => 'common', 'billing' => 'fixed', 'fixed_amount' => '15,50',
        ]));

        $this->assertSame(UtilityBilling::Fixed, $account->billing);
        $this->assertSame('15.50', $account->fixed_amount);
        $this->assertNull($account->number);
        $this->assertNull($account->provider_id);
    }

    public function test_fixed_amount_is_required_for_fixed_and_not_kept_for_actual(): void
    {
        $property = $this->property();

        $errors = $this->errorsOf(fn () => $this->accounts->create($this->data([$property], ['billing' => 'fixed', 'fixed_amount' => ''])));
        $this->assertArrayHasKey('fixed_amount', $errors);

        $errors = $this->errorsOf(fn () => $this->accounts->create($this->data([$property], ['billing' => 'fixed', 'fixed_amount' => '0'])));
        $this->assertArrayHasKey('fixed_amount', $errors);

        $account = $this->accounts->create($this->data([$property], ['fixed_amount' => '20.00']));
        $this->assertNull($account->fixed_amount, 'реалният абонат няма фиксирана сума');
    }

    public function test_actual_account_needs_provider_and_number(): void
    {
        $property = $this->property();

        $errors = $this->errorsOf(fn () => $this->accounts->create($this->data([$property], ['provider_id' => null, 'number' => ' '])));

        $this->assertArrayHasKey('provider_id', $errors);
        $this->assertArrayHasKey('number', $errors);
    }

    public function test_all_errors_come_together_and_nothing_is_saved(): void
    {
        $errors = $this->errorsOf(fn () => $this->accounts->create([
            'provider_id' => null, 'number' => '', 'kind' => 'wine', 'billing' => '', 'valid_from' => '', 'properties' => [],
        ]));

        // Доставчикът и номерът зависят от „реален или фиксиран“: без избор не се проверяват.
        foreach (['kind', 'billing', 'valid_from', 'properties'] as $field) {
            $this->assertArrayHasKey($field, $errors, $field);
        }
        $this->assertSame(0, UtilityAccount::query()->count());
    }

    public function test_provider_of_another_company_is_rejected(): void
    {
        $this->actingAs($this->otherStaff);
        $foreign = Provider::query()->create(['name' => 'Чужд доставчик']);
        $this->actingAs($this->staff);

        $errors = $this->errorsOf(fn () => $this->accounts->create($this->data([$this->property()], ['provider_id' => $foreign->id])));

        $this->assertArrayHasKey('provider_id', $errors);
    }

    // Номерът

    public function test_same_provider_and_number_is_rejected_whatever_the_leading_zeros(): void
    {
        $this->accounts->create($this->data([$this->property()], ['number' => '0012345']));

        foreach (['0012345', '12345', '000012345'] as $number) {
            $errors = $this->errorsOf(fn () => $this->accounts->create($this->data([$this->property()], ['number' => $number])));
            $this->assertArrayHasKey('number', $errors, $number);
        }

        $this->assertSame(1, UtilityAccount::query()->count());
    }

    public function test_archived_account_still_holds_its_number(): void
    {
        $account = $this->accounts->create($this->data([$this->property()]));
        $account->archive();

        $errors = $this->errorsOf(fn () => $this->accounts->create($this->data([$this->property()], ['number' => '12345'])));

        $this->assertArrayHasKey('number', $errors);
    }

    public function test_same_number_with_another_provider_is_allowed(): void
    {
        $other = Provider::query()->create(['name' => 'Измислена Вода']);
        $this->accounts->create($this->data([$this->property()]));

        $account = $this->accounts->create($this->data([$this->property()], ['provider_id' => $other->id, 'kind' => 'water']));

        $this->assertSame('0012345', $account->number);
    }

    public function test_number_comparison_key_ignores_leading_zeros_only(): void
    {
        $this->assertSame('12345', UtilityAccount::numberKey('0012345'));
        $this->assertSame('12345', UtilityAccount::numberKey(' 12345 '));
        $this->assertSame('102030', UtilityAccount::numberKey('00102030'));
        $this->assertSame('0', UtilityAccount::numberKey('000'));
        $this->assertSame('12345', UtilityAccount::numberKey('12345.0'), 'EasyPay записва номера като число');
    }

    public function test_one_property_may_have_several_accounts_of_one_kind(): void
    {
        $property = $this->property();
        $other = Provider::query()->create(['name' => 'Втори доставчик на ток']);

        $this->accounts->create($this->data([$property]));
        $this->accounts->create($this->data([$property], ['provider_id' => $other->id, 'number' => '777']));
        $this->accounts->create($this->data([$property], ['provider_id' => null, 'number' => null, 'billing' => 'fixed', 'fixed_amount' => '10']));

        $this->assertSame(3, UtilityAccount::query()->count());
    }

    // Период на потребление

    public function test_period_days_are_checked(): void
    {
        $property = $this->property();

        $account = $this->accounts->create($this->data([$property], ['period_start_day' => '5', 'period_end_day' => '20']));
        $this->assertSame(5, $account->period_start_day);
        $this->assertSame(20, $account->period_end_day);

        $errors = $this->errorsOf(fn () => $this->accounts->create($this->data([$property], ['number' => '1', 'period_start_day' => '21', 'period_end_day' => '20'])));
        $this->assertArrayHasKey('period_end_day', $errors);

        $errors = $this->errorsOf(fn () => $this->accounts->create($this->data([$property], ['number' => '2', 'period_start_day' => '0', 'period_end_day' => '32'])));
        $this->assertArrayHasKey('period_start_day', $errors);
        $this->assertArrayHasKey('period_end_day', $errors);
    }

    public function test_empty_start_day_is_the_first(): void
    {
        $account = $this->accounts->create($this->data([$this->property()], ['period_start_day' => '', 'period_end_day' => '']));

        $this->assertSame(1, $account->period_start_day);
        $this->assertNull($account->period_end_day);
    }

    // Дати на действие

    public function test_valid_to_cannot_be_before_valid_from(): void
    {
        $errors = $this->errorsOf(fn () => $this->accounts->create($this->data([$this->property()], ['valid_to' => '2025-12-31'])));

        $this->assertArrayHasKey('valid_to', $errors);
    }

    // Имоти и съотношение

    public function test_shared_account_33_33_33(): void
    {
        [$a, $b, $c] = [$this->property(), $this->property(), $this->property()];

        $account = $this->accounts->create($this->data([
            ['property_id' => $a->id, 'share' => '33'],
            ['property_id' => $b->id, 'share' => '33,00'],
            ['property_id' => $c->id, 'share' => '33.00'],
        ]));

        // Сборът е 99%: остатъкът отива към последния имот автоматично (при вноса).
        $this->assertSame(
            [[$a->id, '33.00', 1], [$b->id, '33.00', 2], [$c->id, '33.00', 3]],
            $account->properties()->get()->map(fn ($p) => [$p->property_id, $p->share, $p->position])->all(),
        );
        $this->assertSame($c->id, $account->remainderPropertyId(), 'остатъкът е към последния имот');
    }

    public function test_shares_over_100_are_rejected(): void
    {
        $errors = $this->errorsOf(fn () => $this->accounts->create($this->data([
            ['property_id' => $this->property()->id, 'share' => '60'],
            ['property_id' => $this->property()->id, 'share' => '50'],
        ])));

        $this->assertArrayHasKey('properties', $errors);
    }

    public function test_each_share_of_a_shared_account_is_required_and_above_zero(): void
    {
        foreach (['', '0', '-5', '100.5', 'abc', '33.333'] as $share) {
            $errors = $this->errorsOf(fn () => $this->accounts->create($this->data([
                ['property_id' => $this->property()->id, 'share' => '40'],
                ['property_id' => $this->property()->id, 'share' => $share],
            ])));
            $this->assertArrayHasKey('properties', $errors, "дял „{$share}“");
        }
    }

    public function test_single_property_always_has_100_percent(): void
    {
        $account = $this->accounts->create($this->data([['property_id' => $this->property()->id, 'share' => '40']]));

        $this->assertSame('100.00', $account->properties()->sole()->share);
    }

    public function test_same_property_twice_is_rejected(): void
    {
        $property = $this->property();

        $errors = $this->errorsOf(fn () => $this->accounts->create($this->data([
            ['property_id' => $property->id, 'share' => '50'],
            ['property_id' => $property->id, 'share' => '50'],
        ])));

        $this->assertArrayHasKey('properties', $errors);
    }

    public function test_empty_property_rows_are_skipped(): void
    {
        $property = $this->property();

        $account = $this->accounts->create($this->data([['property_id' => '', 'share' => ''], ['property_id' => $property->id], ['property_id' => null]]));

        $this->assertSame([$property->id], $account->properties()->pluck('property_id')->all());
    }

    public function test_archived_or_foreign_property_is_rejected(): void
    {
        $archived = $this->property();
        $archived->archive();

        $this->actingAs($this->otherStaff);
        $foreign = Property::factory()->create();
        $this->actingAs($this->staff);

        foreach ([$archived, $foreign] as $property) {
            $errors = $this->errorsOf(fn () => $this->accounts->create($this->data([$property])));
            $this->assertArrayHasKey('properties', $errors);
        }
    }

    public function test_update_replaces_fields_and_properties(): void
    {
        [$a, $b] = [$this->property(), $this->property()];
        $account = $this->accounts->create($this->data([$a]));

        $this->accounts->update($account, $this->data([
            ['property_id' => $b->id, 'share' => '40'],
            ['property_id' => $a->id, 'share' => '60'],
        ], ['number' => '0012345', 'note' => 'общ електромер']));

        $account->refresh();
        $this->assertSame('общ електромер', $account->note);
        $this->assertSame(
            [[$b->id, '40.00'], [$a->id, '60.00']],
            $account->properties()->get()->map(fn ($p) => [$p->property_id, $p->share])->all(),
        );
        $this->assertSame($a->id, $account->remainderPropertyId());
    }

    public function test_update_does_not_clash_with_its_own_number(): void
    {
        $account = $this->accounts->create($this->data([$this->property()]));

        $this->accounts->update($account, $this->data([$this->property()], ['number' => '12345']));

        $this->assertSame('12345', $account->fresh()->number);
    }

    // Достъп

    public function test_read_only_staff_cannot_save(): void
    {
        $property = $this->property();
        $this->actingAs(User::factory()->create(['company_id' => $this->staff->company_id, 'access_level' => 'read']));

        $this->expectException(AuthorizationException::class);
        $this->accounts->create($this->data([$property]));
    }

    public function test_assigned_only_staff_sees_accounts_with_at_least_one_of_their_properties(): void
    {
        [$mine, $notMine, $other] = [$this->property(), $this->property(), $this->property()];
        $shared = $this->accounts->create($this->data([['property_id' => $notMine->id, 'share' => '50'], ['property_id' => $mine->id, 'share' => '50']]));
        $this->accounts->create($this->data([$other], ['number' => '999']));

        $assigned = User::factory()->assignedPropertiesOnly()->create(['company_id' => $this->staff->company_id]);
        UserProperty::query()->create(['user_id' => $assigned->id, 'property_id' => $mine->id]);
        $this->actingAs($assigned);

        $this->assertSame([$shared->id], UtilityAccount::query()->pluck('id')->all());
        $this->assertSame(2, $shared->properties()->count(), 'целият абонат, с всички имоти');
    }

    public function test_company_a_does_not_see_accounts_or_providers_of_company_b(): void
    {
        $this->accounts->create($this->data([$this->property()]));

        $this->actingAs($this->otherStaff);

        $this->assertSame(0, UtilityAccount::query()->count());
        $this->assertSame(0, Provider::query()->count());
    }

    public function test_changes_go_to_the_audit_log(): void
    {
        $account = $this->accounts->create($this->data([$this->property()]));

        $this->assertDatabaseHas('audit_log', ['auditable_type' => 'utility_accounts', 'auditable_id' => $account->id, 'event' => 'created']);
    }
}
