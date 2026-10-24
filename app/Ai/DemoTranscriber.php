<?php

namespace App\Ai;

use App\Models\Lesson;

/**
 * Offline stand-in for a school without AI: it never looks at the audio and returns null, so the page falls back to the
 * on-device recognition of the teacher's browser (or to typing the notes by hand).
 */
class DemoTranscriber implements Transcriber
{
    public function transcribe(Lesson $lesson, string $audio, string $mimeType): ?string
    {
        return null;
    }
}
