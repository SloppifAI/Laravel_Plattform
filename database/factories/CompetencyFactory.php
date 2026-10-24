<?php

namespace Database\Factories;

use App\Models\Competency;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Competency>
 */
class CompetencyFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'subject' => null,
            'area' => fake()->randomElement(['Fachwissen', 'Erkenntnisgewinnung', 'Kommunikation', 'Bewertung']),
            'name' => fake()->sentence(3),
            'description' => fake()->sentence(),
        ];
    }
}
