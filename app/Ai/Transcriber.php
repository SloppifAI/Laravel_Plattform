<?php

namespace App\Ai;

use App\Models\LessonRecording;

/**
 * Server-side speech-to-text for a lesson recording. This is the only place in the platform where audio could ever
 * leave the server, so it is bound to the real provider only when `services.transcription.enabled` is true
 * (AppServiceProvider), otherwise to the DemoTranscriber, which cannot process audio.
 *
 * Callers must have authorised the recording (LessonRecordingPolicy) and persist the result themselves.
 */
interface Transcriber
{
    /**
     * Transcribe the recording's audio file (German) into plain text.
     *
     * @throws TranscriberException with a German message the teacher can read when no transcription is possible
     */
    public function transcribe(LessonRecording $recording): string;
}
