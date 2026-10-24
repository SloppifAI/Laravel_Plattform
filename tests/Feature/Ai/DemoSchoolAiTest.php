<?php

namespace Tests\Feature\Ai;

use App\Models\School;
use Database\Seeders\DemoSchoolSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The seeded demo school can try the real AI right away; the second demo school stays on the demo logic.
 */
class DemoSchoolAiTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_demo_school_has_the_ai_switched_on_with_the_complete_checklist_and_the_other_one_has_not(): void
    {
        $this->seed(DemoSchoolSeeder::class);

        $lernpark = School::query()->where('school_number', '4711')->sole();
        $gymnasium = School::query()->where('school_number', '4712')->sole();

        $this->assertTrue($lernpark->isAiChecklistComplete());
        $this->assertNotNull($lernpark->ai_enabled_at);
        $this->assertSame('schulleitung', $lernpark->aiEnabledBy->username);
        $this->assertNull($gymnasium->ai_enabled_at);
        $this->assertNull($gymnasium->ai_checklist);

        // The platform switch still decides: the tests (and a platform without a key) keep every school on the demo logic.
        config(['lernplattform.ai.enabled' => false]);
        $this->assertFalse($lernpark->aiEnabled());

        config(['lernplattform.ai.enabled' => true]);
        $this->assertTrue($lernpark->aiEnabled());
        $this->assertFalse($gymnasium->aiEnabled());
    }

    public function test_seeding_again_leaves_what_the_administration_changed(): void
    {
        $this->seed(DemoSchoolSeeder::class);
        $lernpark = School::query()->where('school_number', '4711')->sole();
        $lernpark->disableAi();

        $this->seed(DemoSchoolSeeder::class);

        $this->assertNull($lernpark->refresh()->ai_enabled_at);
    }
}
