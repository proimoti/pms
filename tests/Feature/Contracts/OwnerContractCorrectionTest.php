<?php

namespace Tests\Feature\Contracts;

use App\Enums\OwnerContractType;
use App\Enums\PaymentMethod;
use App\Models\AuditLog;
use App\Models\Beneficiary;
use App\Models\OwnerContract;
use App\Models\User;
use App\Models\UserProperty;
use Illuminate\Validation\ValidationException;

/**
 * Поправка направо на полетата, от които зависят сумите (PROJECT.md, т. 2
 * „Поправки“): собственик, дати на редовете, имот, сума, процент, бенефициент.
 * Само докато няма начисления; после — само с анекс. Всяка поправка е в журнала
 * и минава през същите проверки (застъпване, вид на собственика).
 */
class OwnerContractCorrectionTest extends ContractTestCase
{
    public function test_corrects_percent_amount_and_payee_in_place_without_new_versions(): void
    {
        $property = $this->property();
        $contract = $this->percentContract([$property]);
        $commission = $contract->commissions()->sole();
        $payee = $contract->payees()->sole();
        $other = Beneficiary::factory()->create(['name' => 'Измислен Получател']);

        $this->contracts->correct($contract, [
            'commissions' => [$commission->id => '11,5'],
            'payees' => [$payee->id => ['beneficiary_id' => $other->id, 'payment_method' => 'cash']],
        ]);

        $this->assertSame(1, $contract->commissions()->count());
        $this->assertSame('11.50', $contract->commissionOn('2026-03-01'));
        $this->assertSame($other->id, $contract->payeeOn('2026-03-01')->beneficiary_id);
        $this->assertSame(PaymentMethod::Cash, $contract->payeeOn('2026-03-01')->payment_method);
        $this->assertTrue(AuditLog::query()->where('auditable_type', 'owner_contract_commissions')->where('event', 'updated')->exists());
    }

    public function test_corrects_the_fixed_amount_and_the_property_of_a_line(): void
    {
        $wrong = $this->property();
        $right = $this->property();
        $contract = $this->fixedContract([$wrong]);
        $line = $contract->lines()->sole();

        $this->contracts->correct($contract, [
            'lines' => [$line->id => ['property_id' => $right->id, 'fixed_amount' => '470']],
        ]);

        $this->assertNull($contract->lineOn($wrong, '2026-03-01'));
        $this->assertSame('470.00', $contract->lineOn($right, '2026-03-01')->fixed_amount);
        $this->assertSame(1, $contract->lines()->count());
    }

    public function test_corrected_start_date_moves_the_start_of_percent_and_payee(): void
    {
        $property = $this->property();
        $contract = $this->percentContract([$property]);
        $line = $contract->lines()->sole();

        $this->contracts->correct($contract, ['lines' => [$line->id => ['valid_from' => '2026-01-01']]]);

        $this->assertSame('2026-01-01', $contract->startsOn()->toDateString());
        $this->assertSame('10.00', $contract->commissionOn('2026-01-01'));
        $this->assertNotNull($contract->payeeOn('2026-01-01'));
    }

    public function test_corrected_date_of_an_annex_version_moves_the_end_of_the_previous_one(): void
    {
        $property = $this->property();
        $contract = $this->fixedContract([$property]);
        $annex = $this->contracts->addAnnex($contract, 1, '2026-05-20');
        $second = $this->contracts->changeFixedAmount($annex, $property, '2026-06-01', '500');

        $this->contracts->correct($contract, ['lines' => [$second->id => ['valid_from' => '2026-07-01']]]);

        $this->assertSame('460.00', $contract->lineOn($property, '2026-06-30')->fixed_amount);
        $this->assertSame('500.00', $contract->lineOn($property, '2026-07-01')->fixed_amount);
    }

    public function test_corrects_the_removal_date_of_a_property(): void
    {
        $a = $this->property();
        $b = $this->property();
        $contract = $this->percentContract([$a, $b]);
        $annex = $this->contracts->addAnnex($contract, 1, '2026-03-15');
        $removed = $this->contracts->removeProperty($annex, $b, '2026-04-01');

        $this->contracts->correct($contract, ['lines' => [$removed->id => ['valid_to' => '2026-05-01']]]);

        $this->assertNotNull($contract->lineOn($b, '2026-04-30'));
        $this->assertNull($contract->lineOn($b, '2026-05-01'));
    }

    public function test_correction_cannot_create_an_overlap(): void
    {
        $busy = $this->property();
        $this->percentContract([$busy]);
        $contract = $this->percentContract([$this->property()]);
        $line = $contract->lines()->sole();

        $this->assertRejected(fn () => $this->contracts->correct($contract, [
            'lines' => [$line->id => ['property_id' => $busy->id]],
        ]), 'lines');
        $this->assertNotSame($busy->id, $line->fresh()->property_id);
    }

    public function test_a_wrong_value_saves_nothing(): void
    {
        $contract = $this->percentContract([$this->property()]);
        $commission = $contract->commissions()->sole();
        $line = $contract->lines()->sole();

        $this->assertRejected(fn () => $this->contracts->correct($contract, [
            'lines' => [$line->id => ['valid_from' => '2026-01-01']],
            'commissions' => [$commission->id => '150'],
        ]), 'commission_percent');

        $this->assertSame('2026-01-10', $line->fresh()->valid_from->toDateString());
    }

    public function test_corrects_the_owner_and_moves_the_owner_himself_payee_to_the_new_owner(): void
    {
        $wrong = $this->owner();
        $right = $this->owner();
        $contract = $this->percentContract([$this->property()], ['owner_id' => $wrong->id]);

        $this->contracts->correct($contract, ['owner_id' => $right->id]);

        $contract->refresh();
        $this->assertSame($right->id, $contract->owner_id);
        $this->assertSame($right->id, $contract->payeeOn('2026-03-01')->beneficiary->owner_id);
    }

    public function test_owner_correction_keeps_another_payee_and_checks_the_kind(): void
    {
        $other = Beneficiary::factory()->create(['name' => 'Измислен Получател']);
        $individual = $this->owner();
        $contract = $this->fixedContract([$this->property()], [
            'type' => OwnerContractType::SubleaseIndividual, 'owner_id' => $individual->id, 'beneficiary_id' => $other->id,
        ]);

        $this->contracts->correct($contract, ['owner_id' => $this->owner()->id]);
        $this->assertSame($other->id, $contract->payeeOn('2026-03-01')->beneficiary_id);

        $this->assertRejected(fn () => $this->contracts->correct($contract->fresh(), ['owner_id' => $this->owner('legal')->id]), 'type');
        $this->assertRejected(fn () => $this->contracts->correct($contract->fresh(), ['owner_id' => null]), 'owner_id');
    }

    public function test_rows_of_another_contract_are_refused(): void
    {
        $first = $this->percentContract([$this->property()]);
        $second = $this->percentContract([$this->property()]);

        $this->assertRejected(fn () => $this->contracts->correct($first, [
            'commissions' => [$second->commissions()->sole()->id => '12'],
        ]), 'commissions');
    }

    public function test_assigned_staff_corrects_only_to_assigned_properties(): void
    {
        $mine = $this->property();
        $notMine = $this->property();
        $contract = $this->percentContract([$mine]);
        $user = User::factory()->assignedPropertiesOnly()->create(['company_id' => $this->staff->company_id]);
        UserProperty::query()->create(['user_id' => $user->id, 'property_id' => $mine->id]);
        $this->actingAs($user);

        $contract = OwnerContract::query()->findOrFail($contract->id);
        $this->assertRejected(fn () => $this->contracts->correct($contract, [
            'lines' => [$contract->lines()->sole()->id => ['property_id' => $notMine->id]],
        ]), 'lines');
    }

    public function test_correction_is_refused_once_there_are_charges(): void
    {
        $contract = new class extends OwnerContract
        {
            public function hasCharges(): bool
            {
                return true;
            }
        };
        $real = $this->percentContract([$this->property()]);
        $contract = $contract->newFromBuilder($real->getAttributes());

        try {
            $this->contracts->correct($contract, ['commissions' => [$real->commissions()->sole()->id => '12']]);
            $this->fail('Поправката мина въпреки начисленията.');
        } catch (ValidationException $e) {
            $this->assertSame([__('contracts.messages.correction_after_charges')], $e->errors()['changes']);
        }
        $this->assertSame('10.00', $real->commissionOn('2026-03-01'));
    }
}
