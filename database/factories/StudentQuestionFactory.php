<?php

namespace Database\Factories;

use App\Models\Lesson;
use App\Models\StudentQuestion;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StudentQuestion>
 */
class StudentQuestionFactory extends Factory
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
            'lesson_id' => Lesson::factory(),
            'explanation_session_id' => null,
            'question' => rtrim(fake()->sentence(), '.').'?',
            'answer' => null,
            'answered_by' => null,
            'answered_at' => null,
            'read_at' => null,
        ];
    }

    /**
     * The lesson's teacher wrote an answer that the student has not read yet.
     */
    public function answered(?string $answer = null): static
    {
        return $this->state(fn (array $attributes) => [
            'answer' => $answer ?? fake()->paragraph(),
            'answered_by' => fn (array $attributes) => Lesson::find($attributes['lesson_id'])?->teacher_id,
            'answered_at' => now(),
        ]);
    }

    public function read(): static
    {
        return $this->state(fn (array $attributes) => ['read_at' => now()]);
    }
}
