<?php

namespace Database\Factories;

use App\MessageAuthor;
use App\Models\ExplanationMessage;
use App\Models\ExplanationSession;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ExplanationMessage>
 */
class ExplanationMessageFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'explanation_session_id' => ExplanationSession::factory(),
            'author' => MessageAuthor::Student,
            'content' => fake()->paragraph(),
        ];
    }
}
