<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\User;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SchoolAiSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_ai_needs_the_platform_switch_the_opt_in_and_the_complete_checklist(): void
    {
        config(['lernplattform.ai.enabled' => true]);
        $school = School::factory()->create();
        $admin = User::factory()->admin()->for($school)->create();

        $this->assertFalse($school->aiEnabled());

        $school->forceFill(['ai_checklist' => array_fill_keys(School::AI_CHECKLIST, true)])->save();
        $school->enableAi($admin);
        $this->assertTrue($school->fresh()->aiEnabled());

        config(['lernplattform.ai.enabled' => false]);
        $this->assertFalse($school->fresh()->aiEnabled());
    }

    public function test_an_incomplete_checklist_cannot_switch_the_ai_on(): void
    {
        $school = School::factory()->create(['ai_checklist' => null]);
        $school->forceFill(['ai_checklist' => ['dpa_with_provider' => true]])->save();

        $this->expectException(DomainException::class);

        $school->enableAi(User::factory()->admin()->for($school)->create());
    }

    public function test_a_blocked_school_never_uses_the_ai(): void
    {
        config(['lernplattform.ai.enabled' => true]);
        $school = School::factory()->blocked()->create();
        $school->forceFill(['ai_checklist' => array_fill_keys(School::AI_CHECKLIST, true), 'ai_enabled_at' => now()])->save();

        $this->assertFalse($school->aiEnabled());
    }
}
