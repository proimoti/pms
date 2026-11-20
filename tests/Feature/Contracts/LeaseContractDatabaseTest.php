<?php

namespace Tests\Feature\Contracts;

use App\Models\LeaseContract;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/** Базата сама отказва грешни записи на договор за наем, дори без приложението. */
class LeaseContractDatabaseTest extends ContractTestCase
{
    private function lineRow(LeaseContract $contract, array $overrides = []): array
    {
        return array_merge([
            'company_id' => $contract->company_id,
            'lease_contract_id' => $contract->id,
            'property_id' => $this->property()->id,
            'valid_from' => '2026-01-10',
            'rent' => '600.00',
            'deposit' => '0.00',
        ], $overrides);
    }

    private function assertDatabaseRefuses(string $constraint, callable $write): void
    {
        try {
            $write();
        } catch (QueryException $e) {
            $this->assertStringContainsString($constraint, $e->getMessage());

            return;
        }

        $this->fail("Базата прие запис, който трябваше да откаже ({$constraint}).");
    }

    public function test_tenant_is_required(): void
    {
        $this->assertDatabaseRefuses('tenant_id', fn () => DB::table('lease_contracts')->insert([
            'company_id' => $this->staff->company_id, 'number' => 900, 'tenant_id' => null, 'signed_on' => '2026-01-10',
        ]));
    }

    public function test_tenant_of_another_company_is_refused(): void
    {
        $foreign = $this->inOtherCompany(fn () => $this->tenant());

        $this->assertDatabaseRefuses('foreign key constraint', fn () => DB::table('lease_contracts')->insert([
            'company_id' => $this->staff->company_id, 'number' => 900, 'tenant_id' => $foreign->id, 'signed_on' => '2026-01-10',
        ]));
    }

    public function test_rent_and_deposit_are_not_negative(): void
    {
        $contract = $this->leaseContract([$this->property()]);

        $this->assertDatabaseRefuses('lease_contract_lines_rent_check',
            fn () => DB::table('lease_contract_lines')->insert($this->lineRow($contract, ['rent' => '-1.00'])));
        $this->assertDatabaseRefuses('lease_contract_lines_deposit_check',
            fn () => DB::table('lease_contract_lines')->insert($this->lineRow($contract, ['deposit' => '-1.00'])));
    }

    public function test_period_end_must_be_after_its_start(): void
    {
        $contract = $this->leaseContract([$this->property()]);

        $this->assertDatabaseRefuses('lease_contract_lines_period_check',
            fn () => DB::table('lease_contract_lines')->insert($this->lineRow($contract, [
                'valid_from' => '2026-05-01', 'valid_to' => '2026-05-01',
            ])));
    }

    public function test_property_of_another_company_is_refused(): void
    {
        $contract = $this->leaseContract([$this->property()]);
        $foreign = $this->inOtherCompany(fn () => $this->property());

        $this->assertDatabaseRefuses('foreign key constraint',
            fn () => DB::table('lease_contract_lines')->insert($this->lineRow($contract, ['property_id' => $foreign->id])));
    }

    public function test_annex_of_one_lease_cannot_be_used_in_another(): void
    {
        $first = $this->leaseContract([$this->property()]);
        $second = $this->leaseContract([$this->property()]);
        $annex = $this->leases->addAnnex($first, 1, '2026-05-20');

        $this->assertDatabaseRefuses('foreign key constraint',
            fn () => DB::table('lease_contract_lines')->insert($this->lineRow($second, [
                'valid_from' => '2026-06-01', 'annex_id' => $annex->id,
            ])));
    }
}
