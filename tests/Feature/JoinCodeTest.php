<?php

namespace Tests\Feature;

use App\Models\SchoolClass;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class JoinCodeTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_classes_get_an_unambiguous_join_code(): void
    {
        $codes = SchoolClass::factory()->count(20)->create()->pluck('join_code');

        $this->assertCount(20, $codes->unique());
        $codes->each(fn (string $code) => $this->assertMatchesRegularExpression('/^[A-HJKMNP-Z2-9]{6}$/', $code));
    }

    public function test_regenerating_replaces_the_code(): void
    {
        $schoolClass = SchoolClass::factory()->create();
        $oldCode = $schoolClass->join_code;

        $schoolClass->regenerateJoinCode();

        $this->assertNotSame($oldCode, $schoolClass->fresh()->join_code);
    }
}
