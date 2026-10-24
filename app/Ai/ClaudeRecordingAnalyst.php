<?php

namespace App\Ai;

use App\Models\LessonRecording;
use App\Support\Pseudonymizer;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * RecordingAnalyst backed by Claude through the Messages API (plain HTTP, like ClaudeLessonAssistant).
 *
 * Only bound when `services.anthropic.enabled` is true. Privacy: the request carries the transcript text only, never
 * audio, and the names of the class's students and of the teacher are replaced by neutral labels first
 * (Pseudonymizer). The labels stay in the result; the teacher reads "Schüler:in A" where a name was said.
 */
class ClaudeRecordingAnalyst implements RecordingAnalyst
{
    private const string ENDPOINT = 'https://api.anthropic.com/v1/messages';

    private const string API_VERSION = '2026-10-21';

    /**
     * Enables `fallbacks: "default"`: a declined request is retried server-side on the recommended fallback model.
     */
    private const string FALLBACK_BETA = 'server-side-fallback-2026-07-01';

    private const string SYSTEM_PROMPT = 'Du bist ein Assistent für Lehrkräfte an deutschen Schulen und schreibst auf Deutsch. Du bekommst das Transkript einer Unterrichtsstunde, das Spracherkennung erzeugt hat: Es kann Fehler, fehlende Satzzeichen und Wiederholungen enthalten. Namen sind durch neutrale Bezeichnungen wie „Schüler:in A“ oder „Lehrkraft“ ersetzt; versuche nie, Personen zu erraten oder zu identifizieren. Arbeite ausschließlich mit dem, was im Transkript steht, und erfinde nichts dazu. Liefere eine Zusammenfassung der Stunde in 3 bis 5 Sätzen (summary), die wichtigsten Fachbegriffe (key_terms, höchstens 10), einen Tafelbild-Vorschlag als kurze Stichpunkte in der Reihenfolge der Stunde (board_outline, höchstens 10), die Fragen, die in der Stunde gestellt wurden (class_questions, höchstens 8, wörtlich oder leicht geglättet) und Stundennotizen (suggested_notes), die die Lehrkraft unverändert in ihre Notizen übernehmen kann: Zusammenfassung, Kernbegriffe und Tafelbild als gut lesbarer Text.';

    public function analyse(LessonRecording $recording, string $transcript): array
    {
        $apiKey = config('services.anthropic.key');

        if (blank($apiKey)) {
            throw new RuntimeException('Die KI-Anbindung ist aktiviert, aber es ist kein API-Schlüssel hinterlegt (ANTHROPIC_API_KEY).');
        }

        $lesson = $recording->lesson;
        $pseudonymizer = Pseudonymizer::forLesson($lesson);

        $text = implode("\n\n", [
            'Fach: '.$pseudonymizer->apply((string) $lesson->subject),
            'Thema: '.$pseudonymizer->apply((string) $lesson->topic),
            "Transkript:\n".$pseudonymizer->apply($transcript),
        ]);

        $message = Http::withHeaders([
            'x-api-key' => $apiKey,
            'anthropic-version' => self::API_VERSION,
            'anthropic-beta' => self::FALLBACK_BETA,
        ])->acceptJson()->timeout(120)->post(self::ENDPOINT, [
            'model' => config('services.anthropic.model') ?: 'claude-opus-5-5',
            'max_tokens' => 16000,
            'fallbacks' => 'default',
            'system' => self::SYSTEM_PROMPT,
            'messages' => [['role' => 'user', 'content' => [['type' => 'text', 'text' => $text]]]],
            'output_config' => [
                'effort' => 'medium',
                'format' => ['type' => 'json_schema', 'schema' => $this->schema()],
            ],
        ])->throw()->json();

        if (($message['stop_reason'] ?? null) === 'refusal') {
            throw new RuntimeException('Die KI hat diese Anfrage abgelehnt. Bitte prüfe das Transkript und versuche es erneut.');
        }

        if (($message['stop_reason'] ?? null) === 'max_tokens') {
            throw new RuntimeException('Die Antwort der KI war zu lang und wurde abgeschnitten. Bitte versuche es erneut.');
        }

        $result = json_decode(collect($message['content'] ?? [])->where('type', 'text')->pluck('text')->implode(''), true);

        if (! is_array($result)) {
            throw new RuntimeException('Die Antwort der KI konnte nicht gelesen werden. Bitte versuche es erneut.');
        }

        return [
            'summary' => trim((string) ($result['summary'] ?? '')),
            'key_terms' => $this->strings($result['key_terms'] ?? [], 10),
            'board_outline' => $this->strings($result['board_outline'] ?? [], 10),
            'class_questions' => $this->strings($result['class_questions'] ?? [], 8),
            'suggested_notes' => trim((string) ($result['suggested_notes'] ?? '')),
        ];
    }

    /**
     * Strict object schema: structured outputs need every property required and no additional ones.
     *
     * @return array<string, mixed>
     */
    private function schema(): array
    {
        $strings = ['type' => 'array', 'items' => ['type' => 'string']];
        $properties = [
            'summary' => ['type' => 'string'],
            'key_terms' => $strings,
            'board_outline' => $strings,
            'class_questions' => $strings,
            'suggested_notes' => ['type' => 'string'],
        ];

        return ['type' => 'object', 'properties' => $properties, 'required' => array_keys($properties), 'additionalProperties' => false];
    }

    /**
     * @return list<string>
     */
    private function strings(mixed $values, int $limit): array
    {
        $texts = array_map(trim(...), array_filter(is_array($values) ? $values : [], is_string(...)));

        return array_slice(array_values(array_filter($texts)), 0, $limit);
    }
}
