<?php

namespace Tests\Feature\Contracts;

use App\Enums\PaymentMethod;
use App\Models\Beneficiary;
use App\Models\OwnerContractCommission;
use Illuminate\Validation\ValidationException;

/**
 * Анекс = нов вариант на реда, на процента или на бенефициента, от дадена дата,
 * с основание (анекс №, дата). Предишният вариант се затваря, историята се пази
 * (PROJECT.md, т. 2 „Договори“ и „Комисиона с история“).
 */
class OwnerContractAnnexTest extends ContractTestCase
{
    public function test_annex_changes_the_percent_from_a_date_and_keeps_the_old_one(): void
    {
        $contract = $this->percentContract([$this->property(), $this->property()]);
        $annex = $this->contracts->addAnnex($contract, 1, '2026-05-20');

        $this->contracts->changeCommission($annex, '2026-06-01', '12.50');

        $this->assertSame('10.00', $contract->commissionOn('2026-05-31'));
        $this->assertSame('12.50', $contract->commissionOn('2026-06-01'));

        $versions = OwnerContractCommission::query()->orderBy('valid_from')->get();
        $this->assertCount(2, $versions);
        $this->assertSame('2026-06-01', $versions[0]->valid_to->toDateString());
        $this->assertNull($versions[0]->annex_id);
        $this->assertSame($annex->id, $versions[1]->annex_id);
        $this->assertSame(1, $annex->number);
        $this->assertSame('2026-05-20', $annex->signed_on->toDateString());
    }

    public function test_percent_cannot_be_changed_on_a_fixed_contract(): void
    {
        $contract = $this->fixedContract([$this->property()]);
        $annex = $this->contracts->addAnnex($contract, 1, '2026-05-20');

        $this->assertRejected(fn () => $this->contracts->changeCommission($annex, '2026-06-01', '12.00'), 'commission_percent');
    }

    public function test_annex_changes_the_fixed_amount_of_one_property(): void
    {
        $a = $this->property();
        $b = $this->property();
        $contract = $this->fixedContract([$a, $b]);
        $annex = $this->contracts->addAnnex($contract, 1, '2026-05-20');

        $this->contracts->changeFixedAmount($annex, $a, '2026-06-01', '500.00');

        $this->assertSame('460.00', $contract->lineOn($a, '2026-05-31')->fixed_amount);
        $this->assertSame('500.00', $contract->lineOn($a, '2026-06-01')->fixed_amount);
        $this->assertSame('460.00', $contract->lineOn($b, '2026-06-01')->fixed_amount);
        $this->assertSame(3, $contract->lines()->count());
    }

    public function test_annex_adds_a_property_from_a_date(): void
    {
        $a = $this->property();
        $b = $this->property();
        $contract = $this->percentContract([$a]);
        $annex = $this->contracts->addAnnex($contract, 1, '2026-03-15');

        $this->contracts->addProperty($annex, $b, '2026-04-01');

        $this->assertSame([$a->id], $contract->linesOn('2026-03-31')->pluck('property_id')->all());
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $contract->linesOn('2026-04-01')->pluck('property_id')->all());
        $this->assertSame($annex->id, $contract->lineOn($b, '2026-04-01')->annex_id);
    }

    public function test_annex_removes_a_property_from_a_date(): void
    {
        $a = $this->property();
        $b = $this->property();
        $contract = $this->percentContract([$a, $b]);
        $annex = $this->contracts->addAnnex($contract, 1, '2026-03-15');

        $this->contracts->removeProperty($annex, $b, '2026-04-01');

        $this->assertNotNull($contract->lineOn($b, '2026-03-31'));
        $this->assertNull($contract->lineOn($b, '2026-04-01'));
        $line = $contract->lines()->where('property_id', $b->id)->sole();
        $this->assertSame('2026-04-01', $line->valid_to->toDateString());
        $this->assertSame($annex->id, $line->closed_by_annex_id);
        // Махнатият имот може да влезе в друг договор от същата дата.
        $this->assertTrue($this->percentContract([$b], ['signed_on' => '2026-04-01'])->exists);
    }

    public function test_adding_by_annex_a_property_that_is_in_another_contract_is_rejected(): void
    {
        $busy = $this->property();
        $this->percentContract([$busy]);
        $contract = $this->percentContract([$this->property()]);
        $annex = $this->contracts->addAnnex($contract, 1, '2026-03-15');

        $this->assertRejected(fn () => $this->contracts->addProperty($annex, $busy, '2026-04-01'), 'lines');
    }

    public function test_annex_changes_the_payee_from_a_date(): void
    {
        $contract = $this->percentContract([$this->property()]);
        $first = $contract->payeeOn('2026-03-01')->beneficiary_id;
        $other = Beneficiary::factory()->create(['name' => 'Измислен Получател']);
        $annex = $this->contracts->addAnnex($contract, 1, '2026-05-20');

        $this->contracts->changePayee($annex, '2026-06-01', $other->id, PaymentMethod::Cash);

        $this->assertSame($first, $contract->payeeOn('2026-05-31')->beneficiary_id);
        $this->assertSame(PaymentMethod::Bank, $contract->payeeOn('2026-05-31')->payment_method);
        $this->assertSame($other->id, $contract->payeeOn('2026-06-01')->beneficiary_id);
        $this->assertSame(PaymentMethod::Cash, $contract->payeeOn('2026-06-01')->payment_method);
    }

    public function test_change_must_start_after_the_current_version(): void
    {
        $contract = $this->percentContract([$this->property()]);
        $annex = $this->contracts->addAnnex($contract, 1, '2026-05-20');

        $this->assertRejected(fn () => $this->contracts->changeCommission($annex, '2026-01-10', '12.00'), 'valid_from');
        $this->assertRejected(fn () => $this->contracts->changeCommission($annex, '2025-12-01', '12.00'), 'valid_from');
        $this->assertSame(1, OwnerContractCommission::query()->count());
    }

    public function test_change_must_be_before_the_termination(): void
    {
        $contract = $this->percentContract([$this->property()]);
        $contract->update(['terminated_on' => '2026-06-01']);
        $annex = $this->contracts->addAnnex($contract->fresh(), 1, '2026-05-20');

        $this->assertRejected(fn () => $this->contracts->changeCommission($annex, '2026-06-01', '12.00'), 'valid_from');
    }

    public function test_annex_numbers_are_unique_in_the_contract(): void
    {
        $contract = $this->percentContract([$this->property()]);
        $this->contracts->addAnnex($contract, 1, '2026-05-20');

        $this->assertRejected(fn () => $this->contracts->addAnnex($contract, 1, '2026-06-20'), 'number');
        $this->assertSame(2, $this->contracts->addAnnex($contract, 2, '2026-06-20')->number);
    }
}
