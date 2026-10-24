<?php

namespace Database\Factories;

use App\Models\ConsentForm;
use App\Models\RecordingConsent;
use App\Models\School;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RecordingConsent>
 */
class RecordingConsentFactory extends Factory
{
    /**
     * Define the model's default state: the parents agreed to every purpose of the school's current form.
     * The token stays empty; a test that needs a link calls regenerateToken().
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'student_id' => User::factory()->student(),
            'school_id' => fn (array $attributes) => User::find($attributes['student_id'])->school_id,
            'consent_form_id' => fn (array $attributes) => School::find($attributes['school_id'])->currentConsentForm()?->getKey()
                ?? ConsentForm::factory()->create(['school_id' => $attributes['school_id']])->getKey(),
            'token_hash' => null,
            'token_generated_at' => null,
            'guardian_name' => fake()->name(),
            'purposes' => fn (array $attributes) => ConsentForm::find($attributes['consent_form_id'])->purposes,
            'granted_at' => now(),
            'revoked_at' => null,
            'source' => RecordingConsent::SOURCE_DIGITAL,
            'recorded_by' => null,
            'student_confirmed_at' => null,
        ];
    }

    /**
     * A letter was printed, but nobody has answered yet.
     */
    public function notAnswered(): static
    {
        return $this->state(fn (array $attributes) => [
            'purposes' => null,
            'guardian_name' => null,
            'granted_at' => null,
            'token_generated_at' => now(),
        ]);
    }

    public function revoked(): static
    {
        return $this->state(fn (array $attributes) => ['revoked_at' => now()]);
    }

    public function studentConfirmed(): static
    {
        return $this->state(fn (array $attributes) => ['student_confirmed_at' => now()]);
    }

    public function forForm(ConsentForm $form): static
    {
        return $this->state(fn (array $attributes) => ['school_id' => $form->school_id, 'consent_form_id' => $form->getKey(), 'purposes' => $form->purposes]);
    }

    /**
     * @param  list<string>  $purposes
     */
    public function agreedTo(array $purposes): static
    {
        return $this->state(fn (array $attributes) => ['purposes' => $purposes]);
    }
}
