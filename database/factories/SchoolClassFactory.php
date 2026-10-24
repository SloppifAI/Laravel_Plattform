<?php

namespace Database\Factories;

use App\Models\School;
use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SchoolClass>
 */
class SchoolClassFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->numerify('Klasse ###'),
            'teacher_id' => User::factory()->teacher(),
            // Same school as the Klassenleitung, also when a test passes its own teacher.
            'school_id' => fn (array $attributes) => User::find($attributes['teacher_id'])?->school_id ?? School::factory(),
        ];
    }
}
