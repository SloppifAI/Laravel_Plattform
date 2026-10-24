<?php

namespace Database\Factories;

use App\Models\ClassBookEntry;
use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * An open draft in a new class, written by a teacher of that class's school.
 *
 * @extends Factory<ClassBookEntry>
 */
class ClassBookEntryFactory extends Factory
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
            'lesson_id' => null,
            'teacher_id' => fn (array $attributes) => User::factory()->teacher()->state([
                'school_id' => SchoolClass::find($attributes['school_class_id'])->school_id,
            ]),
            'held_on' => fake()->dateTimeBetween('-2 weeks'),
            'period' => null,
            'subject' => fake()->randomElement(['Biologie', 'Mathematik', 'Geschichte', 'Deutsch']),
            'topic' => fake()->sentence(3),
            'content' => fake()->sentence(),
            'homework' => null,
            'absent_student_ids' => null,
            'remarks' => null,
            'confirmed_at' => null,
        ];
    }

    public function confirmed(): static
    {
        return $this->state(fn (array $attributes) => ['confirmed_at' => now()]);
    }

    /**
     * @param  iterable<User>  $students
     */
    public function absent(iterable $students): static
    {
        return $this->state(fn (array $attributes) => ['absent_student_ids' => collect($students)->map->getKey()->values()->all()]);
    }
}
