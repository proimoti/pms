<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Property;
use App\Models\PropertyType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Само измислени имоти.
 *
 * @extends Factory<Property>
 */
class PropertyFactory extends Factory
{
    protected $model = Property::class;

    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            // Видът е от същата фирма като имота.
            'property_type_id' => fn (array $attributes) => PropertyType::factory()->create(['company_id' => $attributes['company_id']])->id,
            'postal_code' => fake()->numerify('####'),
            'city' => fake()->randomElement(['София', 'Пловдив', 'Варна', 'Бургас']),
            'district' => 'Измислен квартал',
            'street' => 'ул. Измислена',
            'street_number' => (string) fake()->numberBetween(1, 200),
            'floor' => (string) fake()->numberBetween(1, 10),
            'apartment' => (string) fake()->numberBetween(1, 60),
            'area' => fake()->randomFloat(2, 25, 200),
        ];
    }
}
