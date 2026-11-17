<?php

namespace Database\Factories;

use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Само измислени фирми.
 *
 * @extends Factory<Company>
 */
class CompanyFactory extends Factory
{
    public function definition(): array
    {
        $eik = fake()->unique()->numerify('#########');

        return [
            'name' => 'Тест '.fake()->unique()->lastName().' ООД',
            'eik' => $eik,
            'vat_number' => 'BG'.$eik,
            'manager_name' => fake()->name(),
            'address' => fake()->address(),
            'email' => fake()->unique()->safeEmail(),
        ];
    }
}
