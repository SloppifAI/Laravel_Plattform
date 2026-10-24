<?php

namespace App\Ai;

use App\Models\LessonRecording;

/**
 * Offline stand-in while no speech-to-text provider is connected: it cannot process audio and says so.
 * The on-device live transcript and pasted text work without it.
 */
class DemoTranscriber implements Transcriber
{
    public function transcribe(LessonRecording $recording): string
    {
        throw new TranscriberException('Die automatische Transkription ist noch nicht verfügbar. Die Aufnahme wurde nicht an einen Dienst gesendet. Du kannst den Text unten selbst einfügen.');
    }
}
