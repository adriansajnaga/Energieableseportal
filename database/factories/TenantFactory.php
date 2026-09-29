<?php

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Tenant>
 */
class TenantFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'debtor_number' => fake()->unique()->numberBetween(10000, 99999),
            'street' => fake()->streetAddress(),
            'zip' => fake()->postcode(),
            'city' => fake()->city(),
            'phone' => fake()->phoneNumber(),
            'email' => fake()->safeEmail(),
            'is_active' => true,
            'active_from' => '2024-01-01',
        ];
    }
}
