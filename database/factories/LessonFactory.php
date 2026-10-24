<?php

namespace Database\Factories;

use App\Models\Lesson;
use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Lesson>
 */
class LessonFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'school_class_id' => SchoolClass::factory(),
            'teacher_id' => User::factory()->teacher(),
            'subject' => fake()->randomElement(['Biologie', 'Mathematik', 'Geschichte', 'Deutsch']),
            'topic' => fake()->sentence(3),
            'held_on' => fake()->dateTimeBetween('-2 weeks'),
            'notes' => fake()->paragraphs(3, true),
            'summary' => null,
            'published_at' => null,
            'due_on' => null,
        ];
    }

    /**
     * Homework is delegated to the given students, or to the whole class when none are given.
     *
     * @param  iterable<User>|null  $students
     */
    public function publishedFor(?iterable $students = null): static
    {
        return $this->state(fn (array $attributes) => [
            'published_at' => now(),
            'due_on' => now()->addWeek(),
        ])->afterCreating(function (Lesson $lesson) use ($students): void {
            $lesson->students()->sync(collect($students ?? $lesson->schoolClass->students)->pluck('id'));
        });
    }
}
