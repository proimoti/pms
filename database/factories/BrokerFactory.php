<?php

namespace Database\Factories;

use App\Models\Broker;
use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Само измислени отговорни лица.
 *
 * @extends Factory<Broker>
 */
class BrokerFactory extends Factory
{
    protected $model = Broker::class;

    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'name' => fake()->name(),
            'phone' => fake()->numerify('0888######'),
            'email' => fake()->unique()->safeEmail(),
        ];
    }
}
