<?php

namespace Database\Factories;

use App\Models\Lesson;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Submission>
 */
class SubmissionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'lesson_id' => Lesson::factory(),
            'student_id' => User::factory(),
            'summary' => fake()->paragraph(),
            'understanding_score' => null,
            'feedback' => null,
            'gaps' => null,
            'evaluated_at' => null,
        ];
    }

    /**
     * The AI check has already run.
     */
    public function evaluated(?int $understandingScore = null): static
    {
        return $this->state(fn (array $attributes) => [
            'understanding_score' => $understandingScore ?? fake()->numberBetween(20, 100),
            'feedback' => fake()->paragraph(),
            'gaps' => [fake()->sentence()],
            'evaluated_at' => now(),
        ]);
    }
}
