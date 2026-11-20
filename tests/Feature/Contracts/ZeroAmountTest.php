<?php

namespace Tests\Feature\Contracts;

use App\Enums\OwnerContractType;
use App\Enums\PaymentMethod;
use App\Models\LeaseContract;
use App\Models\OwnerContract;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use PragmaRX\Google2FA\Google2FA;

/**
 * Фиксираната сума и наемът са 0 или повече (PROJECT.md, „Дати на договорите“
 * и „Договор за наем“, променено 20.11.2026): 0 е за придружаващ имот,
 * например гараж към апартамент. Отрицателна сума не се приема.
 */
class ZeroAmountTest extends ContractTestCase
{
    // Договор за наем

    public function test_lease_line_can_have_zero_rent(): void
    {
        $flat = $this->property();
        $garage = $this->property();

        $contract = $this->leaseContract([], ['lines' => [
            ['property_id' => $flat->id, 'rent' => '600'],
            ['property_id' => $garage->id, 'rent' => '0'],
        ]]);

        $this->assertSame('0.00', $contract->lineOn($garage, '2026-03-01')->rent);
    }

    public function test_lease_annex_can_set_and_add_zero_rent(): void
    {
        $flat = $this->property();
        $garage = $this->property();
        $contract = $this->leaseContract([$flat]);

        $this->leases->applyAnnex($contract, [
            'signed_on' => '2026-05-20', 'from' => '2026-06-01',
            'lines' => [
                ['action' => 'change', 'property_id' => $flat->id, 'rent' => '0,00'],
                ['action' => 'add', 'property_id' => $garage->id, 'rent' => '0'],
            ],
        ]);

        $this->assertSame('0.00', $contract->lineOn($flat, '2026-06-01')->rent);
        $this->assertSame('0.00', $contract->lineOn($garage, '2026-06-01')->rent);
    }

    public function test_lease_correction_can_set_zero_rent(): void
    {
        $property = $this->property();
        $contract = $this->leaseContract([$property]);
        $line = $contract->lines()->sole();

        $this->leases->correct($contract, ['lines' => [$line->id => ['rent' => '0']]]);

        $this->assertSame('0.00', $line->fresh()->rent);
    }

    public function test_database_accepts_zero_rent_but_not_negative(): void
    {
        $contract = $this->leaseContract([$this->property()]);
        $row = (array) DB::table('lease_contract_lines')->where('lease_contract_id', $contract->id)->first();
        unset($row['id']);

        DB::table('lease_contract_lines')->insert(['property_id' => $this->property()->id, 'rent' => '0.00'] + $row);

        $this->assertSame(1, DB::table('lease_contract_lines')->where('rent', '0.00')->count());
        $this->assertSame(1, LeaseContract::query()->count());
    }

    // Договор за управление, фиксиран

    public function test_fixed_contract_line_can_have_zero_amount(): void
    {
        $flat = $this->property();
        $garage = $this->property();

        $contract = $this->fixedContract([], ['lines' => [
            ['property_id' => $flat->id, 'fixed_amount' => '460'],
            ['property_id' => $garage->id, 'fixed_amount' => '0'],
        ]]);

        $this->assertSame('0.00', $contract->lineOn($garage, '2026-03-01')->fixed_amount);
    }

    public function test_fixed_contract_annex_and_correction_accept_zero(): void
    {
        $flat = $this->property();
        $garage = $this->property();
        $contract = $this->fixedContract([$flat]);

        $this->contracts->applyAnnex($contract, [
            'signed_on' => '2026-05-20', 'from' => '2026-06-01',
            'lines' => [
                ['action' => 'amount', 'property_id' => $flat->id, 'fixed_amount' => '0'],
                ['action' => 'add', 'property_id' => $garage->id, 'fixed_amount' => '0.00'],
            ],
        ]);

        $this->assertSame('0.00', $contract->lineOn($flat, '2026-06-01')->fixed_amount);
        $this->assertSame('0.00', $contract->lineOn($garage, '2026-06-01')->fixed_amount);

        $other = $this->fixedContract([$this->property()]);
        $line = $other->lines()->sole();
        $this->contracts->correct($other, ['lines' => [$line->id => ['fixed_amount' => '0']]]);
        $this->assertSame('0.00', $line->fresh()->fixed_amount);
    }

    public function test_fixed_amount_cannot_be_negative_or_missing(): void
    {
        $property = $this->property();

        foreach ([null, '', '-1', '-0.01', 'abc', '1.234'] as $amount) {
            $this->assertRejected(fn () => $this->fixedContract([], [
                'lines' => [['property_id' => $property->id, 'fixed_amount' => $amount]],
            ]), 'lines');
        }

        $this->assertSame(0, OwnerContract::query()->count());
    }

    public function test_database_accepts_zero_fixed_amount(): void
    {
        $contract = $this->fixedContract([$this->property()]);
        $row = (array) DB::table('owner_contract_lines')->where('owner_contract_id', $contract->id)->first();
        unset($row['id']);

        DB::table('owner_contract_lines')->insert(['property_id' => $this->property()->id, 'fixed_amount' => '0.00'] + $row);

        $this->assertSame(1, DB::table('owner_contract_lines')->where('fixed_amount', '0.00')->count());
    }

    // Екраните

    public function test_forms_accept_zero(): void
    {
        $owner = $this->owner();
        $tenant = $this->tenant();
        $a = $this->property();
        $b = $this->property();
        // Екраните искат служител с настроен втори фактор.
        $this->actingAs(User::factory()->withTwoFactor(app(Google2FA::class)->generateSecretKey())
            ->create(['company_id' => $this->staff->company_id]));

        $this->post('/owner-contracts', [
            'type' => OwnerContractType::RepresentativeFixed->value, 'owner_id' => $owner->id, 'signed_on' => '10.01.2026',
            'payment_method' => PaymentMethod::Cash->value,
            'lines' => [['property_id' => $a->id, 'fixed_amount' => '0']],
        ])->assertSessionHasNoErrors();

        $this->post('/lease-contracts', [
            'tenant_id' => $tenant->id, 'signed_on' => '10.01.2026',
            'lines' => [['property_id' => $b->id, 'rent' => '0']],
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, OwnerContract::query()->count());
        $this->assertSame(1, LeaseContract::query()->count());
    }

    public function test_messages_say_zero_or_more(): void
    {
        $this->assertStringContainsString('0 или повече', __('contracts.messages.rent_required'));
        $this->assertStringContainsString('0 или повече', __('contracts.messages.amount_required'));
    }
}
