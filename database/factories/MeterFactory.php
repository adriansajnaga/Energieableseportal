<?php

namespace Database\Factories;

use App\Models\Meter;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Meter>
 */
class MeterFactory extends Factory
{
    public function definition(): array
    {
        return [
            'number' => (string) fake()->unique()->numerify('1ESY########'),
            'location' => 'Halle '.fake()->numberBetween(1, 20),
            'factor' => 1,
            'is_main' => false,
            'is_active' => true,
        ];
    }

    public function main(): static
    {
        return $this->state(fn () => ['is_main' => true, 'location' => 'Hauptverteilung']);
    }
}
