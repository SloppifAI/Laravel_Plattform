<?php

namespace Database\Factories;

use App\Models\DpaAcceptance;
use App\Models\School;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DpaAcceptance>
 */
class DpaAcceptanceFactory extends Factory
{
    /**
     * Define the model's default state: an acceptance of the current version with a short stand-in text.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'school_id' => School::factory(),
            'version' => config('lernplattform.dpa.version'),
            'fingerprint' => DpaAcceptance::fingerprint(),
            'content' => '<p>'.fake()->sentence().'</p>',
            'accepted_at' => now(),
            'accepted_by' => null,
            'accepted_by_name' => fake()->name(),
            'accepted_by_username' => fake()->userName(),
        ];
    }
}
