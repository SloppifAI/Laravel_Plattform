<?php

namespace Database\Factories;

use App\Models\School;
use App\Models\SchoolClass;
use App\Models\User;
use App\Role;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'username' => fake()->unique()->userName(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'role' => Role::Student,
            // school_class_id comes first: Laravel resolves attributes in order and student() derives the school from the class.
            'school_class_id' => null,
            'school_id' => School::factory(),
        ];
    }

    /**
     * Runs the platform and belongs to no school.
     */
    public function operator(): static
    {
        return $this->state(fn (array $attributes) => ['role' => Role::Operator, 'school_id' => null, 'school_class_id' => null]);
    }

    public function admin(): static
    {
        return $this->state(fn (array $attributes) => ['role' => Role::Admin, 'school_class_id' => null]);
    }

    public function teacher(): static
    {
        return $this->state(fn (array $attributes) => ['role' => Role::Teacher, 'school_class_id' => null]);
    }

    /**
     * A student, in the given class or in a new one, and in that class's school.
     */
    public function student(?SchoolClass $schoolClass = null): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => Role::Student,
            'school_class_id' => $schoolClass ?? SchoolClass::factory(),
            'school_id' => fn (array $attributes) => SchoolClass::find($attributes['school_class_id'])->school_id,
        ]);
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
