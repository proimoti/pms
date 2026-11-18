<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\PropertyType;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PropertyType> */
class PropertyTypeFactory extends Factory
{
    protected $model = PropertyType::class;

    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'name' => 'Вид '.fake()->unique()->numberBetween(1, 1_000_000),
        ];
    }
}
