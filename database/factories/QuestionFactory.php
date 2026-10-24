<?php

namespace Database\Factories;

use App\Models\Lesson;
use App\Models\Question;
use App\QuestionType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Question>
 */
class QuestionFactory extends Factory
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
            'competency_id' => null,
            'type' => fake()->randomElement(QuestionType::cases()),
            'prompt' => fake()->sentence().'?',
            'expected_answer' => fake()->paragraph(),
            'position' => 0,
            'ai_drafted' => false,
        ];
    }

    /**
     * A question of the AI draft the teacher has not edited yet.
     */
    public function aiDrafted(): static
    {
        return $this->state(fn (array $attributes): array => ['ai_drafted' => true]);
    }
}
