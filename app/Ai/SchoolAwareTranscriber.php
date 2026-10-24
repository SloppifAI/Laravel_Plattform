<?php

namespace App\Ai;

use App\Models\Lesson;

/**
 * The Transcriber the application uses: a segment goes to the SdkTranscriber only when the school of the lesson has the
 * AI switched on (School::aiEnabled(): platform switch, the school's opt-in and its complete checklist), otherwise the
 * DemoTranscriber looks at nothing and returns null, so no sound of a school without opt-in ever leaves the platform.
 */
class SchoolAwareTranscriber implements Transcriber
{
    public function __construct(private readonly SdkTranscriber $sdk, private readonly DemoTranscriber $demo) {}

    public function transcribe(Lesson $lesson, string $audio, string $mimeType): ?string
    {
        $transcriber = $lesson->schoolClass?->school?->aiEnabled() ? $this->sdk : $this->demo;

        return $transcriber->transcribe($lesson, $audio, $mimeType);
    }
}
