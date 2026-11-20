<?php

namespace Tests\Feature\Contracts;

use App\Models\LeaseContract;
use App\Models\LeaseContractAnnex;
use App\Models\LeaseContractLine;
use Illuminate\Validation\ValidationException;

/**
 * Договор за наем: анекс с няколко промени наведнъж, поправка на място и
 * предупреждение за имот без договор за управление (PROJECT.md, т. 2
 * „Договор за наем“, „Поправки“, „Удължаване с анекс“).
 */
class LeaseContractFormRulesTest extends ContractTestCase
{
    // Анекс

    public function test_one_annex_changes_rent_and_deposit_adds_removes_and_extends(): void
    {
        $a = $this->property();
        $b = $this->property();
        $new = $this->property();
        $contract = $this->leaseContract([$a, $b], ['end_on' => '2026-12-31']);

        $annex = $this->leases->applyAnnex($contract, [
            'number' => 1, 'signed_on' => '2026-05-20', 'note' => 'Измислено основание', 'from' => '2026-06-01',
            'end_on' => '2027-12-31',
            'lines' => [
                ['action' => 'change', 'property_id' => $a->id, 'rent' => '650', 'deposit' => '1300'],
                ['action' => 'add', 'property_id' => $new->id, 'from' => '2026-07-01', 'rent' => '300', 'deposit' => ''],
                ['action' => 'remove', 'property_id' => $b->id],
            ],
        ]);

        $this->assertSame(1, $annex->number);
        $this->assertSame('650.00', $contract->lineOn($a, '2026-06-01')->rent);
        $this->assertSame('1300.00', $contract->lineOn($a, '2026-06-01')->deposit);
        $this->assertSame('600.00', $contract->lineOn($a, '2026-05-31')->rent);
        $this->assertNull($contract->lineOn($b, '2026-06-01'));
        $this->assertNull($contract->lineOn($new, '2026-06-30'));
        $this->assertSame('0.00', $contract->lineOn($new, '2026-07-01')->deposit);
        $this->assertSame('2027-12-31', $contract->fresh()->end_on->toDateString());
    }

    public function test_change_with_only_rent_keeps_the_deposit(): void
    {
        $property = $this->property();
        $contract = $this->leaseContract([$property]);

        $this->leases->applyAnnex($contract, [
            'signed_on' => '2026-05-20', 'from' => '2026-06-01',
            'lines' => [['action' => 'change', 'property_id' => $property->id, 'rent' => '650', 'deposit' => '']],
        ]);

        $this->assertSame('650.00', $contract->lineOn($property, '2026-06-01')->rent);
        $this->assertSame('1200.00', $contract->lineOn($property, '2026-06-01')->deposit);
    }

    public function test_a_wrong_change_saves_nothing_not_even_the_annex(): void
    {
        $busy = $this->property();
        $this->leaseContract([$busy]);
        $contract = $this->leaseContract([$this->property()], ['end_on' => '2026-12-31']);

        $this->assertRejected(fn () => $this->leases->applyAnnex($contract, [
            'signed_on' => '2026-05-20', 'from' => '2026-06-01', 'end_on' => '2027-12-31',
            'lines' => [['action' => 'add', 'property_id' => $busy->id, 'rent' => '300']],
        ]), 'lines');

        $this->assertSame(0, LeaseContractAnnex::query()->count());
        $this->assertSame('2026-12-31', $contract->fresh()->end_on->toDateString());
    }

    public function test_annex_needs_a_change_and_a_date_and_a_known_action(): void
    {
        $property = $this->property();
        $contract = $this->leaseContract([$property]);

        $this->assertRejected(fn () => $this->leases->applyAnnex($contract, ['signed_on' => '2026-05-20', 'from' => '2026-06-01']), 'changes');
        $this->assertRejected(fn () => $this->leases->applyAnnex($contract, [
            'signed_on' => '2026-05-20', 'lines' => [['action' => 'change', 'property_id' => $property->id, 'rent' => '650']],
        ]), 'from');
        $this->assertRejected(fn () => $this->leases->applyAnnex($contract, [
            'signed_on' => '2026-05-20', 'from' => '2026-06-01', 'lines' => [['action' => 'amount', 'property_id' => $property->id]],
        ]), 'lines');
        $this->assertRejected(fn () => $this->leases->applyAnnex($contract, [
            'signed_on' => '2026-05-20', 'from' => '2026-06-01',
            'lines' => [['action' => 'change', 'property_id' => $property->id, 'rent' => '', 'deposit' => '']],
        ]), 'lines');
    }

    // Поправка

    public function test_corrects_tenant_property_dates_rent_and_deposit_in_place(): void
    {
        $wrong = $this->property();
        $right = $this->property();
        $contract = $this->leaseContract([$wrong]);
        $line = $contract->lines()->sole();
        $tenant = $this->tenant();

        $this->leases->correct($contract, [
            'tenant_id' => $tenant->id,
            'lines' => [$line->id => ['property_id' => $right->id, 'valid_from' => '2026-01-05', 'rent' => '610', 'deposit' => '1220']],
        ]);

        $contract->refresh();
        $this->assertSame($tenant->id, $contract->tenant_id);
        $this->assertSame(1, $contract->lines()->count());
        $this->assertSame('610.00', $contract->lineOn($right, '2026-01-05')->rent);
        $this->assertSame('1220.00', $contract->lineOn($right, '2026-01-05')->deposit);
    }

    public function test_corrected_date_of_an_annex_version_moves_the_end_of_the_previous_one(): void
    {
        $property = $this->property();
        $contract = $this->leaseContract([$property]);
        $annex = $this->leases->addAnnex($contract, 1, '2026-05-20');
        $second = $this->leases->changeRent($annex, $property, '2026-06-01', '650');

        $this->leases->correct($contract, ['lines' => [$second->id => ['valid_from' => '2026-07-01']]]);

        $this->assertSame('600.00', $contract->lineOn($property, '2026-06-30')->rent);
        $this->assertSame('650.00', $contract->lineOn($property, '2026-07-01')->rent);
    }

    public function test_correction_checks_values_tenant_and_overlap_and_saves_nothing_on_error(): void
    {
        $busy = $this->property();
        $this->leaseContract([$busy]);
        $contract = $this->leaseContract([$this->property()]);
        $line = $contract->lines()->sole();
        $foreign = $this->inOtherCompany(fn () => $this->tenant());

        $this->assertRejected(fn () => $this->leases->correct($contract, ['lines' => [$line->id => ['property_id' => $busy->id]]]), 'lines');
        $this->assertRejected(fn () => $this->leases->correct($contract, ['lines' => [$line->id => ['rent' => '0']]]), 'lines');
        $this->assertRejected(fn () => $this->leases->correct($contract, ['tenant_id' => $foreign->id]), 'tenant_id');
        $this->assertRejected(fn () => $this->leases->correct($contract, ['tenant_id' => null]), 'tenant_id');
        $this->assertRejected(fn () => $this->leases->correct($contract, [
            'lines' => [$line->id => ['valid_from' => '2026-01-01', 'deposit' => '-5']],
        ]), 'lines');

        $this->assertSame('2026-01-10', $line->fresh()->valid_from->toDateString());
        $this->assertSame('600.00', $line->fresh()->rent);
    }

    public function test_correction_is_refused_once_there_are_charges(): void
    {
        $real = $this->leaseContract([$this->property()]);
        $contract = (new class extends LeaseContract
        {
            public function hasCharges(): bool
            {
                return true;
            }
        })->newFromBuilder($real->getAttributes());

        try {
            $this->leases->correct($contract, ['lines' => [$real->lines()->sole()->id => ['rent' => '700']]]);
            $this->fail('Поправката мина въпреки начисленията.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('changes', $e->errors());
        }
        $this->assertSame('600.00', LeaseContractLine::query()->sole()->rent);
    }

    // Предупреждение: имот без договор за управление за същия период

    public function test_lists_the_properties_without_a_management_contract_for_the_lease_period(): void
    {
        $covered = $this->property();
        $partly = $this->property();
        $none = $this->property();
        $this->percentContract([$covered], ['signed_on' => '2026-01-01']);
        $management = $this->percentContract([$partly], ['signed_on' => '2026-01-01']);
        $management->update(['terminated_on' => '2026-06-01']);

        $lease = $this->leaseContract([$covered, $partly, $none]);

        $this->assertSame([$partly->number, $none->number], $this->leases->propertiesWithoutManagement($lease));
    }

    public function test_archived_management_contract_does_not_cover_and_a_terminated_lease_needs_less(): void
    {
        $property = $this->property();
        $archived = $this->percentContract([$property], ['signed_on' => '2026-01-01']);
        $archived->archive();
        $lease = $this->leaseContract([$property]);

        $this->assertSame([$property->number], $this->leases->propertiesWithoutManagement($lease));

        $management = $this->percentContract([$property], ['signed_on' => '2026-01-01']);
        $management->update(['terminated_on' => '2026-06-01']);
        $lease->update(['terminated_on' => '2026-06-01']);

        $this->assertSame([], $this->leases->propertiesWithoutManagement($lease->fresh()));
    }
}
