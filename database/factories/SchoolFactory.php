<?php

namespace Database\Factories;

use App\Models\School;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<School>
 */
class SchoolFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->randomElement(['Gesamtschule', 'Gymnasium', 'Realschule']).' '.fake()->unique()->city(),
            'school_number' => (string) fake()->unique()->numberBetween(100000, 999999),
            'school_type' => fake()->randomElement(['Gesamtschule', 'Gymnasium', 'Realschule', 'Grundschule']),
            'city' => fake()->city(),
            'state' => 'Hessen',
            'contact_email' => fake()->safeEmail(),
        ];
    }

    public function blocked(): static
    {
        return $this->state(fn (array $attributes) => ['blocked_at' => now()]);
    }
}
