<?php

namespace Tests\Feature\Ai;

use App\Ai\Agents\HomeworkDrafter;
use App\Ai\Agents\LessonSummarizer;
use Laravel\Ai\Enums\Lab;
use Tests\TestCase;

class ThinkingLevelTest extends TestCase
{
    public function test_gemini_gets_the_configured_thinking_level_whether_the_gateway_names_it_by_string_or_enum(): void
    {
        config(['lernplattform.ai.thinking_level' => 'low']);

        foreach ([new HomeworkDrafter, new LessonSummarizer] as $agent) {
            $this->assertSame(['thinking_level' => 'low'], $agent->providerOptions('gemini'));
            $this->assertSame(['thinking_level' => 'low'], $agent->providerOptions(Lab::Gemini));
        }
    }

    public function test_other_providers_and_an_empty_level_send_nothing(): void
    {
        config(['lernplattform.ai.thinking_level' => 'low']);
        $this->assertSame([], (new HomeworkDrafter)->providerOptions('ollama'));
        $this->assertSame([], (new HomeworkDrafter)->providerOptions(Lab::OpenAI));

        config(['lernplattform.ai.thinking_level' => null]);
        $this->assertSame([], (new HomeworkDrafter)->providerOptions('gemini'));
    }
}
