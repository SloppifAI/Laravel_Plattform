<?php

namespace Tests\Feature\Ai;

use App\Models\School;
use App\Models\User;
use Illuminate\Support\Facades\Http;

/**
 * Lets a test opt a school into the real AI. Tests never reach a provider: every agent a test uses is faked
 * (`HomeworkDrafter::fake([...])`), and any HTTP request that would still go out fails the test.
 */
trait EnablesSchoolAi
{
    protected function preventRealAiCalls(): void
    {
        Http::preventStrayRequests();
        config([
            'lernplattform.ai.enabled' => true,
            'lernplattform.ai.provider' => 'gemini',
            'lernplattform.ai.model' => 'gemini-test-model',
        ]);
    }

    /**
     * Platform switch on (see preventRealAiCalls) and the school opted in with the complete checklist.
     */
    protected function enableAiFor(School $school): School
    {
        $school->forceFill(['ai_checklist' => array_fill_keys(School::AI_CHECKLIST, true)])->save();
        $school->enableAi(User::factory()->for($school)->admin()->create());

        return $school->refresh();
    }
}
