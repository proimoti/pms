<?php

namespace Database\Factories;

use App\Models\Beneficiary;
use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Само измислени бенефициенти, без IBAN по подразбиране
 * (IBAN се задава изрично в тестовете и изисква право).
 *
 * @extends Factory<Beneficiary>
 */
class BeneficiaryFactory extends Factory
{
    protected $model = Beneficiary::class;

    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'name' => fake()->name(),
        ];
    }
}
