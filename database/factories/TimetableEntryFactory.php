<?php

namespace Database\Factories;

use App\Models\SchoolClass;
use App\Models\TimetableEntry;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Monday, first period in a new class, taught by its Klassenleitung. A class has one entry per slot, so a test that
 * creates several in the same class places them with at().
 *
 * @extends Factory<TimetableEntry>
 */
class TimetableEntryFactory extends Factory
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
            'teacher_id' => fn (array $attributes) => SchoolClass::find($attributes['school_class_id'])->teacher_id,
            'weekday' => 1,
            'period' => 1,
            'subject' => fake()->randomElement(['Biologie', 'Mathematik', 'Geschichte', 'Deutsch', 'Englisch']),
            'room' => fake()->numerify('R ###'),
        ];
    }

    /**
     * The slot: ISO weekday (1 = Monday) and period.
     */
    public function at(int $weekday, int $period): static
    {
        return $this->state(fn (array $attributes) => ['weekday' => $weekday, 'period' => $period]);
    }
}
