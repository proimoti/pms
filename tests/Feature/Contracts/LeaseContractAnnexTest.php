<?php

namespace Tests\Feature\Contracts;

use App\Models\LeaseContractLine;

/**
 * Анекс към договор за наем: нов вариант на реда (наем, депозит, имот) от
 * дадена дата, с основание (анекс №, дата). Предишният вариант се затваря,
 * историята се пази (PROJECT.md, т. 2 „Договори“).
 */
class LeaseContractAnnexTest extends ContractTestCase
{
    public function test_annex_changes_the_rent_from_a_date_and_keeps_the_old_one(): void
    {
        $a = $this->property();
        $b = $this->property();
        $contract = $this->leaseContract([$a, $b]);
        $annex = $this->leases->addAnnex($contract, 1, '2026-05-20');

        $this->leases->changeRent($annex, $a, '2026-06-01', '650.00');

        $this->assertSame('600.00', $contract->lineOn($a, '2026-05-31')->rent);
        $this->assertSame('650.00', $contract->lineOn($a, '2026-06-01')->rent);
        // Депозитът и другият имот не се променят.
        $this->assertSame('1200.00', $contract->lineOn($a, '2026-06-01')->deposit);
        $this->assertSame('600.00', $contract->lineOn($b, '2026-06-01')->rent);

        $versions = LeaseContractLine::query()->where('property_id', $a->id)->orderBy('valid_from')->get();
        $this->assertCount(2, $versions);
        $this->assertSame('2026-06-01', $versions[0]->valid_to->toDateString());
        $this->assertNull($versions[0]->annex_id);
        $this->assertSame($annex->id, $versions[1]->annex_id);
        $this->assertSame('2026-05-20', $annex->signed_on->toDateString());
    }

    public function test_annex_changes_the_agreed_deposit_and_keeps_the_rent(): void
    {
        $property = $this->property();
        $contract = $this->leaseContract([$property]);
        $annex = $this->leases->addAnnex($contract, 1, '2026-05-20');

        $this->leases->changeDeposit($annex, $property, '2026-06-01', '1500.00');

        $this->assertSame('1200.00', $contract->lineOn($property, '2026-05-31')->deposit);
        $this->assertSame('1500.00', $contract->lineOn($property, '2026-06-01')->deposit);
        $this->assertSame('600.00', $contract->lineOn($property, '2026-06-01')->rent);
    }

    public function test_new_rent_and_deposit_must_be_valid(): void
    {
        $property = $this->property();
        $contract = $this->leaseContract([$property]);
        $annex = $this->leases->addAnnex($contract, 1, '2026-05-20');

        $this->assertRejected(fn () => $this->leases->changeRent($annex, $property, '2026-06-01', '-1'), 'lines');
        $this->assertRejected(fn () => $this->leases->changeDeposit($annex, $property, '2026-06-01', '-1'), 'lines');
        $this->assertSame(1, LeaseContractLine::query()->count());
    }

    public function test_annex_adds_a_property_from_a_date(): void
    {
        $a = $this->property();
        $b = $this->property();
        $contract = $this->leaseContract([$a]);
        $annex = $this->leases->addAnnex($contract, 1, '2026-03-15');

        $this->leases->addProperty($annex, $b, '2026-04-01', '300.00', '300.00');

        $this->assertSame([$a->id], $contract->linesOn('2026-03-31')->pluck('property_id')->all());
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $contract->linesOn('2026-04-01')->pluck('property_id')->all());
        $this->assertSame($annex->id, $contract->lineOn($b, '2026-04-01')->annex_id);
        $this->assertSame('300.00', $contract->lineOn($b, '2026-04-01')->rent);
    }

    public function test_annex_removes_a_property_from_a_date(): void
    {
        $a = $this->property();
        $b = $this->property();
        $contract = $this->leaseContract([$a, $b]);
        $annex = $this->leases->addAnnex($contract, 1, '2026-03-15');

        $this->leases->removeProperty($annex, $b, '2026-04-01');

        $this->assertNotNull($contract->lineOn($b, '2026-03-31'));
        $this->assertNull($contract->lineOn($b, '2026-04-01'));
        $line = $contract->lines()->where('property_id', $b->id)->sole();
        $this->assertSame('2026-04-01', $line->valid_to->toDateString());
        $this->assertSame($annex->id, $line->closed_by_annex_id);
        // Махнатият имот може да влезе в друг договор за наем от същата дата.
        $this->assertTrue($this->leaseContract([$b], ['signed_on' => '2026-04-01'])->exists);
    }

    public function test_adding_by_annex_a_property_that_is_in_another_lease_is_rejected(): void
    {
        $busy = $this->property();
        $this->leaseContract([$busy]);
        $contract = $this->leaseContract([$this->property()]);
        $annex = $this->leases->addAnnex($contract, 1, '2026-03-15');

        $this->assertRejected(fn () => $this->leases->addProperty($annex, $busy, '2026-04-01', '300.00'), 'lines');
    }

    public function test_change_must_start_after_the_current_version_and_before_termination(): void
    {
        $property = $this->property();
        $contract = $this->leaseContract([$property]);
        $annex = $this->leases->addAnnex($contract, 1, '2026-05-20');

        $this->assertRejected(fn () => $this->leases->changeRent($annex, $property, '2026-01-10', '650'), 'valid_from');
        $this->assertRejected(fn () => $this->leases->addProperty($annex, $this->property(), '2026-01-01', '300'), 'valid_from');

        $contract->update(['terminated_on' => '2026-08-01']);
        $this->assertRejected(fn () => $this->leases->changeRent($annex, $property, '2026-08-01', '650'), 'valid_from');
        $this->assertSame(1, LeaseContractLine::query()->count());
    }

    public function test_property_not_in_the_lease_cannot_be_changed(): void
    {
        $contract = $this->leaseContract([$this->property()]);
        $annex = $this->leases->addAnnex($contract, 1, '2026-05-20');

        $this->assertRejected(fn () => $this->leases->changeRent($annex, $this->property(), '2026-06-01', '650'), 'lines');
    }

    public function test_annex_numbers_are_unique_in_the_lease(): void
    {
        $contract = $this->leaseContract([$this->property()]);
        $other = $this->leaseContract([$this->property()]);
        $this->leases->addAnnex($contract, 1, '2026-05-20');

        $this->assertRejected(fn () => $this->leases->addAnnex($contract, 1, '2026-06-20'), 'number');
        $this->assertSame(1, $this->leases->addAnnex($other, 1, '2026-06-20')->number);
    }
}
