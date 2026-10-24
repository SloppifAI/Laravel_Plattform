<?php

namespace Database\Factories;

use App\Models\ConsentForm;
use App\Models\School;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ConsentForm>
 */
class ConsentFormFactory extends Factory
{
    /**
     * Define the model's default state. A published version 1 that offers every purpose.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'school_id' => School::factory(),
            'version' => 1,
            'title' => ConsentForm::defaultTitle(),
            'body' => 'Wir möchten den Ton einzelner Unterrichtsstunden aufzeichnen. '.fake()->sentence(),
            'purposes' => array_keys(ConsentForm::PURPOSES),
            'published_at' => now(),
            'created_by' => null,
        ];
    }

    public function version(int $version): static
    {
        return $this->state(fn (array $attributes) => ['version' => $version]);
    }

    public function draft(): static
    {
        return $this->state(fn (array $attributes) => ['published_at' => null]);
    }

    /**
     * @param  list<string>  $purposes
     */
    public function offering(array $purposes): static
    {
        return $this->state(fn (array $attributes) => ['purposes' => $purposes]);
    }
}
