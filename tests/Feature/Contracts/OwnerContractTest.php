<?php

namespace Tests\Feature\Contracts;

use App\Enums\CalculationMethod;
use App\Enums\OwnerContractType;
use App\Enums\PaymentMethod;
use App\Models\AuditLog;
use App\Models\Beneficiary;
use App\Models\OwnerContract;
use App\Models\OwnerContractLine;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Договор за управление (PROJECT.md, т. 2 „Договори“ и „Договори, уточнения“,
 * т. 3.6): заглавие, тип от петте, редове по имот, процент на ниво договор,
 * бенефициент и начин на плащане по периоди.
 */
class OwnerContractTest extends ContractTestCase
{
    // Заглавие и тип

    public function test_percent_contract_is_created_with_its_lines_and_percent(): void
    {
        $a = $this->property();
        $b = $this->property();

        $contract = $this->percentContract([$a, $b], ['end_on' => '2027-01-10']);

        $this->assertSame(1, $contract->number);
        $this->assertSame(OwnerContractType::RepresentativePercent, $contract->type);
        $this->assertSame(CalculationMethod::Percent, $contract->calculation);
        $this->assertSame('2026-01-10', $contract->signed_on->toDateString());
        $this->assertSame('2027-01-10', $contract->end_on->toDateString());
        $this->assertSame('10.00', $contract->commissionOn('2026-03-01'));
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $contract->linesOn('2026-03-01')->pluck('property_id')->all());
        $this->assertNull($contract->lines()->first()->fixed_amount);
    }

    public function test_the_five_types_map_to_three_calculation_methods(): void
    {
        $this->assertSame(CalculationMethod::Percent, OwnerContractType::RepresentativePercent->calculation());
        $this->assertSame(CalculationMethod::Fixed, OwnerContractType::RepresentativeFixed->calculation());
        $this->assertSame(CalculationMethod::Fixed, OwnerContractType::SubleaseIndividual->calculation());
        $this->assertSame(CalculationMethod::Fixed, OwnerContractType::SubleaseLegal->calculation());
        $this->assertSame(CalculationMethod::Own, OwnerContractType::Own->calculation());
    }

    public function test_percent_lives_on_the_contract_not_on_the_lines(): void
    {
        $this->assertFalse(\Schema::hasColumn('owner_contract_lines', 'percent'));
        $this->assertFalse(\Schema::hasColumn('owner_contract_lines', 'commission_percent'));
    }

    public function test_percent_contract_needs_a_percent_and_no_fixed_amounts(): void
    {
        $property = $this->property();

        $this->assertRejected(fn () => $this->percentContract([$property], ['commission_percent' => null]), 'commission_percent');
        $this->assertRejected(fn () => $this->percentContract([$property], [
            'lines' => [['property_id' => $property->id, 'fixed_amount' => '100.00']],
        ]), 'lines');
        $this->assertSame(0, OwnerContract::query()->count());
    }

    public function test_fixed_contract_needs_an_amount_on_every_line_and_no_percent(): void
    {
        $a = $this->property();
        $b = $this->property();

        $contract = $this->fixedContract([$a]);
        $this->assertSame('460.00', $contract->lines()->sole()->fixed_amount);
        $this->assertNull($contract->commissionOn('2026-03-01'));

        $this->assertRejected(fn () => $this->fixedContract([$b], [
            'lines' => [['property_id' => $b->id]],
        ]), 'lines');
        $this->assertRejected(fn () => $this->fixedContract([$b], ['commission_percent' => '10.00']), 'commission_percent');
    }

    public function test_own_contract_has_no_owner_percent_amount_or_payee(): void
    {
        $property = $this->property();

        $contract = $this->contracts->create([
            'type' => OwnerContractType::Own,
            'signed_on' => '2026-01-10',
            'lines' => [['property_id' => $property->id]],
        ]);

        $this->assertNull($contract->owner_id);
        $this->assertNull($contract->commissionOn('2026-03-01'));
        $this->assertNull($contract->payeeOn('2026-03-01'));
        $this->assertNull($contract->lines()->sole()->fixed_amount);

        $other = $this->property();
        $this->assertRejected(fn () => $this->contracts->create([
            'type' => OwnerContractType::Own,
            'signed_on' => '2026-01-10',
            'lines' => [['property_id' => $other->id, 'fixed_amount' => '100.00']],
        ]), 'lines');
    }

    public function test_other_types_need_an_owner(): void
    {
        $this->assertRejected(fn () => $this->percentContract([$this->property()], ['owner_id' => null]), 'owner_id');
    }

    public function test_sublease_type_must_match_the_kind_of_the_owner(): void
    {
        $individual = $this->owner('individual');
        $legal = $this->owner('legal');

        $ok = $this->fixedContract([$this->property()], ['type' => OwnerContractType::SubleaseIndividual, 'owner_id' => $individual->id]);
        $this->assertSame(OwnerContractType::SubleaseIndividual, $ok->type);

        $this->assertRejected(fn () => $this->fixedContract([$this->property()], [
            'type' => OwnerContractType::SubleaseIndividual, 'owner_id' => $legal->id,
        ]), 'type');
        $this->assertRejected(fn () => $this->fixedContract([$this->property()], [
            'type' => OwnerContractType::SubleaseLegal, 'owner_id' => $individual->id,
        ]), 'type');
    }

    public function test_owner_kind_cannot_change_against_a_sublease_contract(): void
    {
        $owner = $this->owner('individual');
        $this->fixedContract([$this->property()], ['type' => OwnerContractType::SubleaseIndividual, 'owner_id' => $owner->id]);

        $this->assertRejected(fn () => $owner->update(['kind' => 'legal', 'eik' => '123456789']), 'kind');
        $this->assertSame('individual', $owner->fresh()->kind->value);
    }

    public function test_type_cannot_be_changed(): void
    {
        $contract = $this->percentContract([$this->property()]);

        $this->assertRejected(fn () => $contract->update(['type' => OwnerContractType::RepresentativeFixed]), 'type');
        $this->assertSame(OwnerContractType::RepresentativePercent, $contract->fresh()->type);
    }

    public function test_contract_numbers_are_the_largest_plus_1_with_manual_change(): void
    {
        $this->assertSame(1, $this->percentContract([$this->property()])->number);
        $this->assertSame(50, $this->percentContract([$this->property()], ['number' => 50])->number);
        $this->assertSame(51, $this->percentContract([$this->property()])->number);

        $this->assertRejected(fn () => $this->percentContract([$this->property()], ['number' => 50]), 'number');
    }

    // Дати и „активен“

    public function test_line_starts_on_the_contract_date_unless_given(): void
    {
        $a = $this->property();
        $b = $this->property();

        $contract = $this->percentContract([$a], [
            'lines' => [['property_id' => $a->id], ['property_id' => $b->id, 'start_on' => '2026-02-01']],
        ]);

        $this->assertSame('2026-01-10', $contract->lines()->where('property_id', $a->id)->sole()->valid_from->toDateString());
        $this->assertSame('2026-02-01', $contract->lines()->where('property_id', $b->id)->sole()->valid_from->toDateString());
        $this->assertSame([$a->id], $contract->linesOn('2026-01-20')->pluck('property_id')->all());
    }

    public function test_active_is_computed_from_the_dates(): void
    {
        $contract = $this->percentContract([$this->property()], ['end_on' => '2026-06-30']);

        $this->assertFalse($contract->isActiveOn('2026-01-09'));
        $this->assertTrue($contract->isActiveOn('2026-01-10'));
        // Без дата на прекратяване действа и след срока.
        $this->assertTrue($contract->isActiveOn('2026-09-01'));

        $contract->update(['terminated_on' => '2026-10-15']);

        $this->assertTrue($contract->fresh()->isActiveOn('2026-10-14'));
        // Денят на прекратяване не се брои (както при наема, т. 3.1).
        $this->assertFalse($contract->fresh()->isActiveOn('2026-10-15'));
        $this->assertFalse(\Schema::hasColumn('owner_contracts', 'is_active'));
    }

    public function test_termination_must_be_after_the_start(): void
    {
        $contract = $this->percentContract([$this->property()]);

        $this->assertRejected(fn () => $contract->update(['terminated_on' => '2026-01-10']), 'terminated_on');
    }

    // Бенефициент и начин на плащане

    public function test_by_default_the_payee_is_the_owner_himself(): void
    {
        $owner = $this->owner();

        $contract = $this->percentContract([$this->property()], ['owner_id' => $owner->id]);

        $payee = $contract->payeeOn('2026-03-01');
        $this->assertSame(PaymentMethod::Bank, $payee->payment_method);
        $this->assertSame($owner->id, $payee->beneficiary->owner_id);
        $this->assertSame($owner->name, $payee->beneficiary->name);
    }

    public function test_the_owner_beneficiary_is_one_record_for_all_his_contracts(): void
    {
        $owner = $this->owner();

        $first = $this->percentContract([$this->property()], ['owner_id' => $owner->id]);
        $second = $this->fixedContract([$this->property()], ['owner_id' => $owner->id]);

        $this->assertSame(1, Beneficiary::query()->where('owner_id', $owner->id)->count());
        $this->assertSame($first->payeeOn('2026-03-01')->beneficiary_id, $second->payeeOn('2026-03-01')->beneficiary_id);
    }

    public function test_another_contract_of_the_same_owner_may_have_another_payee(): void
    {
        $owner = $this->owner();
        $other = Beneficiary::factory()->create(['name' => 'Измислен Получател']);

        $this->percentContract([$this->property()], ['owner_id' => $owner->id]);
        $second = $this->percentContract([$this->property()], ['owner_id' => $owner->id, 'beneficiary_id' => $other->id]);

        $this->assertSame($other->id, $second->payeeOn('2026-03-01')->beneficiary_id);
    }

    public function test_payee_of_another_company_is_rejected(): void
    {
        $foreign = $this->inOtherCompany(fn () => Beneficiary::factory()->create());

        $this->assertRejected(fn () => $this->percentContract([$this->property()], ['beneficiary_id' => $foreign->id]), 'beneficiary_id');
    }

    public function test_payment_method_is_required_except_for_own(): void
    {
        $this->assertRejected(fn () => $this->percentContract([$this->property()], ['payment_method' => null]), 'payment_method');
    }

    // Защита: един имот в един договор за управление за един период

    public function test_property_cannot_be_in_two_contracts_at_the_same_time(): void
    {
        $property = $this->property();
        $first = $this->percentContract([$property]);

        try {
            $this->fixedContract([$property], ['signed_on' => '2026-05-01']);
            $this->fail('Имотът влезе в два договора за управление.');
        } catch (ValidationException $e) {
            $this->assertSame(
                ["Имот № {$property->number} вече е в договор за управление № {$first->number} за този период."],
                $e->errors()['lines'],
            );
        }

        $this->assertSame(1, OwnerContract::query()->count());
    }

    public function test_new_contract_may_start_on_the_day_the_old_one_is_terminated(): void
    {
        $property = $this->property();
        $first = $this->percentContract([$property]);
        $first->update(['terminated_on' => '2026-06-01']);

        $second = $this->fixedContract([$property], ['signed_on' => '2026-06-01']);

        $this->assertTrue($second->exists);
        $this->assertRejected(fn () => $this->fixedContract([$this->property()], [
            'signed_on' => '2026-05-01',
            'lines' => [['property_id' => $property->id, 'fixed_amount' => '1.00']],
        ]), 'lines');
    }

    public function test_cancelling_a_termination_cannot_create_an_overlap(): void
    {
        $property = $this->property();
        $first = $this->percentContract([$property]);
        $first->update(['terminated_on' => '2026-06-01']);
        $this->fixedContract([$property], ['signed_on' => '2026-06-01']);

        $this->assertRejected(fn () => $first->fresh()->update(['terminated_on' => null]), 'lines');
        $this->assertSame('2026-06-01', $first->fresh()->terminated_on->toDateString());
    }

    public function test_same_property_cannot_be_twice_in_one_contract(): void
    {
        $property = $this->property();

        $this->assertRejected(fn () => $this->percentContract([$property, $property]), 'lines');
    }

    public function test_archived_contract_does_not_count_and_cannot_come_back_over_another(): void
    {
        $property = $this->property();
        $wrong = $this->percentContract([$property]);
        $wrong->archive();

        $right = $this->percentContract([$property]);

        $this->assertTrue($right->exists);
        $this->assertRejected(fn () => $wrong->fresh()->restoreFromArchive(), 'lines');
        $this->assertTrue($wrong->fresh()->isArchived());
    }

    public function test_property_of_another_company_is_rejected(): void
    {
        $foreign = $this->inOtherCompany(fn () => $this->property());

        $this->assertRejected(fn () => $this->percentContract([$foreign]), 'lines');
    }

    // Права, фирми, триене, журнал

    public function test_read_only_staff_cannot_create_a_contract(): void
    {
        $property = $this->property();
        $owner = $this->owner();
        $this->actingAs(User::factory()->readOnly()->create(['company_id' => $this->staff->company_id]));

        $this->expectException(AuthorizationException::class);

        $this->percentContract([$property], ['owner_id' => $owner->id]);
    }

    public function test_another_company_sees_none_of_the_contract_tables(): void
    {
        $contract = $this->percentContract([$this->property()]);
        $annex = $this->contracts->addAnnex($contract, 1, '2026-05-20');
        $this->contracts->changeCommission($annex, '2026-06-01', '12.00');

        $this->actingAs($this->otherStaff);

        $this->assertSame(0, OwnerContract::query()->count());
        $this->assertSame(0, OwnerContractLine::query()->count());
        $this->assertSame(0, \App\Models\OwnerContractAnnex::query()->count());
        $this->assertSame(0, \App\Models\OwnerContractCommission::query()->count());
        $this->assertSame(0, \App\Models\OwnerContractPayee::query()->count());
    }

    public function test_contracts_and_lines_are_never_deleted(): void
    {
        $contract = $this->percentContract([$this->property()]);

        $this->expectException(LogicException::class);
        $contract->lines()->first()->delete();
    }

    public function test_contract_cannot_be_deleted(): void
    {
        $contract = $this->percentContract([$this->property()]);

        $this->expectException(LogicException::class);
        $contract->delete();
    }

    public function test_creation_is_logged_in_the_journal(): void
    {
        $contract = $this->percentContract([$this->property()]);

        $this->assertTrue(AuditLog::query()
            ->where('auditable_type', 'owner_contracts')->where('auditable_id', $contract->id)
            ->where('event', 'created')->exists());
        $this->assertTrue(AuditLog::query()->where('auditable_type', 'owner_contract_lines')->exists());
    }
}
