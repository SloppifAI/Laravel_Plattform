<?php

namespace Database\Factories;

use App\Models\ClassSubstitution;
use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClassSubstitution>
 */
class ClassSubstitutionFactory extends Factory
{
    /**
     * Define the model's default state: a teacher of the class's school covers it from today for a week, set up by the
     * Klassenleitung.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'school_class_id' => SchoolClass::factory(),
            'substitute_id' => fn (array $attributes) => User::factory()->teacher()->state([
                'school_id' => SchoolClass::find($attributes['school_class_id'])->school_id,
            ]),
            'granted_by' => fn (array $attributes) => SchoolClass::find($attributes['school_class_id'])->teacher_id,
            'starts_on' => today(),
            'ends_on' => today()->addDays(6),
            'note' => null,
        ];
    }

    /**
     * Ended the given number of days ago (at least 1) after lasting five days.
     */
    public function ended(int $daysAgo = 3): static
    {
        return $this->state(fn (array $attributes) => ['starts_on' => today()->subDays($daysAgo + 4), 'ends_on' => today()->subDays($daysAgo)]);
    }

    /**
     * Starts in the given number of days and lasts a week.
     */
    public function upcoming(int $inDays = 3): static
    {
        return $this->state(fn (array $attributes) => ['starts_on' => today()->addDays($inDays), 'ends_on' => today()->addDays($inDays + 6)]);
    }
}
