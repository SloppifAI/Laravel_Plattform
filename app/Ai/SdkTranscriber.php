<?php

namespace App\Ai;

use App\Models\Lesson;
use Laravel\Ai\Files\Audio;
use Laravel\Ai\Transcription;
use Throwable;

/**
 * Transcriber backed by the Laravel AI SDK (provider and model from `config('lernplattform.ai.*')`, Gemini by default).
 *
 * Reached only through SchoolAwareTranscriber, i.e. for schools that opted in (School::aiEnabled()). The segment goes
 * to the provider from memory as inline data (no file on any disk, no upload to a file store), without speaker
 * separation, and nothing but the recognised text comes back.
 */
class SdkTranscriber implements Transcriber
{
    /**
     * Seconds the provider has for a segment of about half a minute.
     */
    private const int TIMEOUT = 45;

    public function transcribe(Lesson $lesson, string $audio, string $mimeType): string
    {
        try {
            $response = Transcription::of(Audio::fromBase64(base64_encode($audio), $mimeType))
                ->language('de')
                ->timeout(self::TIMEOUT)
                ->generate((string) config('lernplattform.ai.provider'), config('lernplattform.ai.transcription_model') ?: null);
        } catch (Throwable $exception) {
            throw new TranscriberException('Ein Abschnitt konnte nicht mitgeschrieben werden.', previous: $exception);
        }

        return trim($response->text);
    }
}
