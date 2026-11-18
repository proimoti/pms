<?php

namespace Database\Factories;

use App\Enums\PartyKind;
use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/** Общо за собственици и наематели. Само измислени данни. */
abstract class PartyFactory extends Factory
{
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'kind' => PartyKind::Individual,
            'name' => fake()->name(),
            'city' => 'София',
            'street' => 'ул. Измислена',
            'street_number' => (string) fake()->numberBetween(1, 200),
            'phone' => fake()->numerify('0888######'),
            'email' => fake()->unique()->safeEmail(),
        ];
    }

    /** Юридическо лице с измислени ЕИК и МОЛ. */
    public function legal(): static
    {
        return $this->state(fn (array $attributes) => [
            'kind' => PartyKind::Legal,
            'name' => 'Тест '.fake()->lastName().' ООД',
            'eik' => fake()->numerify('#########'),
            'manager_name' => fake()->name(),
        ]);
    }
}
