<?php

namespace Database\Factories;

use App\Models\Answer;
use App\Models\Question;
use App\Models\Submission;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Answer>
 */
class AnswerFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'submission_id' => Submission::factory(),
            'question_id' => Question::factory(),
            'answer' => fake()->paragraph(),
            'score' => null,
            'feedback' => null,
        ];
    }
}
