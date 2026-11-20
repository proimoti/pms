<?php

namespace Tests\Feature\Contracts;

use App\Enums\OwnerContractType;
use App\Enums\PaymentMethod;
use App\Models\Owner;
use App\Models\OwnerContract;
use App\Models\Property;
use App\Models\User;
use App\Services\OwnerContractService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** Общи помощници за тестовете на договорите. Само измислени данни. */
abstract class ContractTestCase extends TestCase
{
    use RefreshDatabase;

    protected User $staff;

    /** Служител на друга фирма. Създава се преди влизането, иначе защитата го слага в нашата фирма. */
    protected User $otherStaff;

    protected OwnerContractService $contracts;

    protected function setUp(): void
    {
        parent::setUp();
        $this->staff = User::factory()->create();
        $this->otherStaff = User::factory()->create();
        $this->actingAs($this->staff);
        $this->contracts = app(OwnerContractService::class);
    }

    protected function property(): Property
    {
        return Property::factory()->create();
    }

    protected function owner(string $kind = 'individual'): Owner
    {
        return $kind === 'legal' ? Owner::factory()->legal()->create() : Owner::factory()->create();
    }

    /** Договор „Представител, %“ с 10% от 10.01.2026 за дадените имоти. */
    protected function percentContract(array $properties, array $overrides = []): OwnerContract
    {
        return $this->contracts->create(array_merge([
            'type' => OwnerContractType::RepresentativePercent,
            'owner_id' => $this->owner()->id,
            'signed_on' => '2026-01-10',
            'commission_percent' => '10.00',
            'payment_method' => PaymentMethod::Bank,
            'lines' => array_map(fn (Property $p) => ['property_id' => $p->id], $properties),
        ], $overrides));
    }

    /** Договор „Представител, фиксиран“ с 460 € на имот. */
    protected function fixedContract(array $properties, array $overrides = []): OwnerContract
    {
        return $this->contracts->create(array_merge([
            'type' => OwnerContractType::RepresentativeFixed,
            'owner_id' => $this->owner()->id,
            'signed_on' => '2026-01-10',
            'payment_method' => PaymentMethod::Cash,
            'lines' => array_map(fn (Property $p) => ['property_id' => $p->id, 'fixed_amount' => '460.00'], $properties),
        ], $overrides));
    }

    /** Очаква отказ (ValidationException) с грешка за даденото поле. */
    protected function assertRejected(callable $action, string $field): void
    {
        try {
            $action();
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($field, $e->errors(), "Отказано е, но с грешка за друго поле: ".json_encode($e->errors(), JSON_UNESCAPED_UNICODE));

            return;
        }

        $this->fail("Очаквах отказ с грешка за „{$field}“.");
    }

    /** Създава запис като служител на друга фирма. */
    protected function inOtherCompany(callable $make): mixed
    {
        $this->actingAs($this->otherStaff);
        $record = $make();
        $this->actingAs($this->staff);

        return $record;
    }
}