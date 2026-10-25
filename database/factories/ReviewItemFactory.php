<?php

namespace Database\Factories;

use App\Models\Question;
use App\Models\ReviewItem;
use App\Models\User;
use App\QuestionType;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<ReviewItem>
 */
class ReviewItemFactory extends Factory
{
    /**
     * A question in box 1 that is due today. It is only offered to a student its lesson was released to
     * (ReviewItem::availableTo()), so tests release the lesson with `Lesson::factory()->publishedFor([$student])`.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'student_id' => User::factory()->student(),
            'question_id' => Question::factory()->state(['type' => QuestionType::Comprehension]),
            'box' => 1,
            'due_on' => today(),
        ];
    }

    public function inBox(int $box): static
    {
        return $this->state(fn (array $attributes): array => ['box' => $box]);
    }

    /**
     * @param  string  $date  a date the application understands, e.g. "2026-10-20" or "yesterday"
     */
    public function dueOn(string $date): static
    {
        return $this->state(fn (array $attributes): array => ['due_on' => Carbon::parse($date)->toDateString()]);
    }
}
