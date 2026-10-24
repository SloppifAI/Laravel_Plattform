<?php

namespace Database\Factories;

use App\Models\ExplanationSession;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ExplanationSession>
 */
class ExplanationSessionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'student_id' => User::factory(),
            'lesson_id' => null,
            'topic' => fake()->sentence(3),
            'understanding_score' => null,
            'feedback' => null,
            'finished_at' => null,
        ];
    }

    public function finished(?int $understandingScore = null): static
    {
        return $this->state(fn (array $attributes) => [
            'understanding_score' => $understandingScore ?? fake()->numberBetween(20, 100),
            'feedback' => fake()->paragraph(),
            'finished_at' => now(),
        ]);
    }
}
