<?php

namespace App\Ai;

use App\Models\Lesson;

/**
 * The RecordingAnalyst the application uses: the notes go to the SdkRecordingAnalyst only when the school of the lesson
 * has the AI switched on (School::aiEnabled(): platform switch, the school's opt-in and its complete checklist),
 * otherwise the offline DemoRecordingAnalyst answers, so nothing of a school without opt-in ever leaves the platform.
 */
class SchoolAwareRecordingAnalyst implements RecordingAnalyst
{
    public function __construct(private readonly SdkRecordingAnalyst $sdk, private readonly DemoRecordingAnalyst $demo) {}

    public function analyse(Lesson $lesson, string $text): array
    {
        $analyst = $lesson->schoolClass?->school?->aiEnabled() ? $this->sdk : $this->demo;

        return $analyst->analyse($lesson, $text);
    }
}
