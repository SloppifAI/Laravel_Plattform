<?php

namespace App\Ai;

use App\Models\LessonRecording;

/**
 * Skeleton for the future speech-to-text provider. Bound only when `services.transcription.enabled` is true.
 *
 * What an implementation has to do:
 *  - read the audio from `LessonRecording::disk()` at `$recording->path` (container `$recording->mime_type`),
 *  - transcribe German speech and return plain text without speaker names,
 *  - send audio only to a provider the school has a data processing agreement (AVV) with, and name that provider in
 *    the privacy texts (Datenschutzerklärung, resources/views/filament/legal/privacy.blade.php) before enabling,
 *  - never log or keep the audio or the text anywhere else,
 *  - throw a TranscriberException with a German message the teacher can read when it fails.
 *
 * The audio itself never goes to the RecordingAnalyst or to any other AI; only the text does, and only pseudonymised.
 */
class SpeechToTextTranscriber implements Transcriber
{
    public function transcribe(LessonRecording $recording): string
    {
        throw new TranscriberException('Die Transkription ist aktiviert, aber es ist noch kein Spracherkennungsdienst angebunden. Bitte wende dich an die Betreuung der Plattform.');
    }
}
