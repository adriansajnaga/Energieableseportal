<?php

namespace Database\Factories;

use App\Enums\Medium;
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

    public function water(Medium $medium = Medium::ColdWater): static
    {
        return $this->state(fn () => [
            'medium' => $medium,
            'number' => (string) fake()->unique()->numerify('WZ########'),
            'calibration_year' => 2024,
        ]);
    }

    public function main(): static
    {
        return $this->state(fn () => ['is_main' => true, 'location' => 'Hauptverteilung']);
    }
}
