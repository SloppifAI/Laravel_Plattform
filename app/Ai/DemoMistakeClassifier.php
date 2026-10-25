<?php

namespace App\Ai;

use App\MistakeSource;
use App\MistakeType;
use App\Models\Answer;
use App\Models\Question;

/**
 * Offline mistake types from fixed text rules, for every school without the AI: an empty answer is "no attempt", one far
 * shorter than the Erwartungshorizont "incomplete", one that shares no content word with the question and the
 * Erwartungshorizont "off topic". Anything else needs understanding the demo does not have, so it says nothing.
 */
class DemoMistakeClassifier implements MistakeClassifier
{
    /**
     * An answer with fewer than this share of the words of the Erwartungshorizont is incomplete.
     */
    private const float SHORT_RATIO = 0.3;

    public function classify(Question $question, Answer $answer, array $evaluation): ?MistakeClassification
    {
        $text = trim((string) $answer->answer);

        if ($text === '') {
            return new MistakeClassification(MistakeType::Empty, 1.0, MistakeSource::Demo);
        }

        $expected = trim((string) $question->expected_answer);

        if ($expected === '') {
            return null;
        }

        if ($this->wordCount($text) < self::SHORT_RATIO * $this->wordCount($expected)) {
            return new MistakeClassification(MistakeType::Incomplete, 1.0, MistakeSource::Demo);
        }

        $asked = $question->promptFor($question->levelFor($answer->level));

        if (array_intersect($this->stems($text), $this->stems($asked.' '.$expected)) === []) {
            return new MistakeClassification(MistakeType::OffTopic, 1.0, MistakeSource::Demo);
        }

        return null;
    }

    private function wordCount(string $text): int
    {
        return count(preg_split('/[^\p{L}\p{N}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: []);
    }

    /**
     * Content words of four letters or more without the demo's stopwords, cut to five letters so that "Sauerstoffs"
     * meets "Sauerstoff".
     *
     * @return list<string>
     */
    private function stems(string $text): array
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_unique(array_map(
            fn (string $word): string => mb_substr($word, 0, 5),
            array_filter($words, fn (string $word): bool => mb_strlen($word) >= 4 && ! in_array($word, DemoLessonAssistant::STOPWORDS, true)),
        )));
    }
}
