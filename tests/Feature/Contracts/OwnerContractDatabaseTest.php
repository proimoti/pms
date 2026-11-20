<?php

namespace Tests\Feature\Contracts;

use App\Models\OwnerContract;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Базата сама отказва грешни комбинации (PROJECT.md, т. 2 „Договори“), дори
 * ако някой пише направо в нея, без приложението.
 */
class OwnerContractDatabaseTest extends ContractTestCase
{
    private function contractRow(array $overrides = []): array
    {
        return array_merge([
            'company_id' => $this->staff->company_id,
            'number' => 900,
            'type' => 'representative_percent',
            'calculation' => 'percent',
            'owner_id' => $this->owner()->id,
            'signed_on' => '2026-01-10',
        ], $overrides);
    }

    private function lineRow(OwnerContract $contract, array $overrides = []): array
    {
        return array_merge([
            'company_id' => $contract->company_id,
            'owner_contract_id' => $contract->id,
            'calculation' => $contract->calculation->value,
            'property_id' => $this->property()->id,
            'valid_from' => '2026-01-10',
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

    public function test_unknown_type_is_refused(): void
    {
        $this->assertDatabaseRefuses('owner_contracts_type_check',
            fn () => DB::table('owner_contracts')->insert($this->contractRow(['type' => 'something'])));
    }

    public function test_type_and_calculation_must_match(): void
    {
        $this->assertDatabaseRefuses('owner_contracts_calculation_check',
            fn () => DB::table('owner_contracts')->insert($this->contractRow(['calculation' => 'fixed'])));
        $this->assertDatabaseRefuses('owner_contracts_calculation_check',
            fn () => DB::table('owner_contracts')->insert($this->contractRow(['type' => 'own', 'calculation' => 'percent'])));
    }

    public function test_owner_is_required_except_for_own(): void
    {
        $this->assertDatabaseRefuses('owner_contracts_owner_check',
            fn () => DB::table('owner_contracts')->insert($this->contractRow(['owner_id' => null])));

        DB::table('owner_contracts')->insert($this->contractRow(['type' => 'own', 'calculation' => 'own', 'owner_id' => null]));
        $this->assertSame(1, DB::table('owner_contracts')->count());
    }

    public function test_line_amount_must_fit_the_calculation(): void
    {
        $percent = $this->percentContract([$this->property()]);
        $fixed = $this->fixedContract([$this->property()]);

        $this->assertDatabaseRefuses('owner_contract_lines_amount_check',
            fn () => DB::table('owner_contract_lines')->insert($this->lineRow($percent, ['fixed_amount' => '100.00'])));
        $this->assertDatabaseRefuses('owner_contract_lines_amount_check',
            fn () => DB::table('owner_contract_lines')->insert($this->lineRow($fixed, ['fixed_amount' => null])));
        $this->assertDatabaseRefuses('owner_contract_lines_amount_check',
            fn () => DB::table('owner_contract_lines')->insert($this->lineRow($fixed, ['fixed_amount' => '-1.00'])));
    }

    public function test_line_cannot_pretend_another_calculation_than_its_contract(): void
    {
        $percent = $this->percentContract([$this->property()]);

        // Редът твърди „фиксиран“, но договорът е с процент: връзката не съвпада.
        $this->assertDatabaseRefuses('foreign key constraint',
            fn () => DB::table('owner_contract_lines')->insert($this->lineRow($percent, [
                'calculation' => 'fixed', 'fixed_amount' => '100.00',
            ])));
    }

    public function test_percent_exists_only_on_percent_contracts_and_is_between_0_and_100(): void
    {
        $percent = $this->percentContract([$this->property()]);
        $fixed = $this->fixedContract([$this->property()]);
        $row = fn (OwnerContract $c, array $o = []) => array_merge([
            'company_id' => $c->company_id, 'owner_contract_id' => $c->id, 'calculation' => $c->calculation->value,
            'valid_from' => '2026-07-01', 'percent' => '10.00',
        ], $o);

        $this->assertDatabaseRefuses('owner_contract_commissions_calculation_check',
            fn () => DB::table('owner_contract_commissions')->insert($row($fixed)));
        $this->assertDatabaseRefuses('owner_contract_commissions_percent_check',
            fn () => DB::table('owner_contract_commissions')->insert($row($percent, ['percent' => '0.00'])));
        $this->assertDatabaseRefuses('owner_contract_commissions_percent_check',
            fn () => DB::table('owner_contract_commissions')->insert($row($percent, ['percent' => '100.00'])));
    }

    public function test_period_end_must_be_after_its_start(): void
    {
        $contract = $this->percentContract([$this->property()]);

        $this->assertDatabaseRefuses('owner_contract_lines_period_check',
            fn () => DB::table('owner_contract_lines')->insert($this->lineRow($contract, [
                'valid_from' => '2026-05-01', 'valid_to' => '2026-05-01',
            ])));
    }

    public function test_payee_has_a_known_payment_method_and_not_on_own_contracts(): void
    {
        $contract = $this->percentContract([$this->property()]);
        $payee = DB::table('owner_contract_payees')->first();
        $row = (array) $payee;
        unset($row['id']);

        $this->assertDatabaseRefuses('owner_contract_payees_method_check',
            fn () => DB::table('owner_contract_payees')->insert(array_merge($row, ['payment_method' => 'crypto', 'valid_from' => '2026-07-01'])));
        $this->assertDatabaseRefuses('owner_contract_payees_calculation_check',
            fn () => DB::table('owner_contract_payees')->insert(array_merge($row, ['calculation' => 'own', 'valid_from' => '2026-07-01'])));
    }

    public function test_annex_of_one_contract_cannot_be_used_in_another(): void
    {
        $first = $this->percentContract([$this->property()]);
        $second = $this->percentContract([$this->property()]);
        $annex = $this->contracts->addAnnex($first, 1, '2026-05-20');

        $this->assertDatabaseRefuses('foreign key constraint',
            fn () => DB::table('owner_contract_lines')->insert($this->lineRow($second, [
                'valid_from' => '2026-06-01', 'annex_id' => $annex->id,
            ])));
    }
}
