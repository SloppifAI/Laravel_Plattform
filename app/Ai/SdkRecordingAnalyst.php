<?php

namespace App\Ai;

use App\Ai\Agents\LessonSummarizer;
use App\Models\Lesson;
use App\Support\Pseudonymizer;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Exceptions\RateLimitedException;
use RuntimeException;

/**
 * RecordingAnalyst backed by the Laravel AI SDK (provider and model from `config('lernplattform.ai.*')`, Gemini by default).
 *
 * Reached only through SchoolAwareRecordingAnalyst, i.e. for schools that opted in (School::aiEnabled()). Privacy: the
 * request carries text only, never audio; the caller has replaced the names in the notes, and the names in the lesson's
 * subject and topic are replaced here. The labels stay in the result ("Schüler:in A" where a name was said).
 */
class SdkRecordingAnalyst implements RecordingAnalyst
{
    public function analyse(Lesson $lesson, string $text): array
    {
        $pseudonymizer = Pseudonymizer::forLesson($lesson);

        $prompt = implode("\n\n", [
            'Fach: '.$pseudonymizer->apply((string) $lesson->subject),
            'Thema: '.$pseudonymizer->apply((string) $lesson->topic),
            "Notizen aus der Stunde:\n".$text,
        ]);

        try {
            $result = (new LessonSummarizer)->prompt($prompt)->toArray();
        } catch (RateLimitedException|ProviderOverloadedException $exception) {
            throw new RuntimeException('Die KI ist gerade überlastet. Bitte versuche es in ein paar Minuten erneut.', previous: $exception);
        } catch (AiException|RequestException|ConnectionException $exception) {
            throw new RuntimeException('Die KI ist gerade nicht erreichbar. Bitte versuche es erneut.', previous: $exception);
        }

        if ($result === []) {
            throw new RuntimeException('Die Antwort der KI konnte nicht gelesen werden. Bitte versuche es erneut.');
        }

        return [
            'summary' => trim((string) ($result['summary'] ?? '')),
            'key_terms' => $this->strings($result['key_terms'] ?? [], LessonSummarizer::MAX_TERMS),
            'board_outline' => $this->strings($result['board_outline'] ?? [], LessonSummarizer::MAX_OUTLINE),
            'class_questions' => $this->strings($result['class_questions'] ?? [], LessonSummarizer::MAX_QUESTIONS),
            'suggested_notes' => trim((string) ($result['suggested_notes'] ?? '')),
        ];
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
