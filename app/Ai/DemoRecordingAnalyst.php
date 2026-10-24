<?php

namespace App\Ai;

use App\Models\Lesson;
use Illuminate\Support\Str;

/**
 * Offline stand-in for the AI: a careful extract of the notes, computed locally and deterministically.
 * The summary consists of the sentences with the most frequent terms, the key terms are the longest-lived words of
 * the lesson, the board outline pairs each term with the sentence that introduces it and the questions are the
 * sentences that end in a question mark. Nothing is invented and nothing leaves the platform.
 */
class DemoRecordingAnalyst implements RecordingAnalyst
{
    private const int SUMMARY_SENTENCES = 3;

    private const int KEY_TERMS = 6;

    private const int MAX_QUESTIONS = 5;

    /**
     * Frequent German words of five letters or more that are no key terms.
     */
    private const array STOP_WORDS = [
        'aber', 'aller', 'alles', 'also', 'andere', 'anderen', 'auch', 'beim', 'bitte', 'dabei', 'dafür', 'damit', 'danach',
        'dann', 'davon', 'denn', 'deshalb', 'dieser', 'diese', 'diesem', 'diesen', 'dieses', 'doch', 'eben', 'eigentlich',
        'einen', 'einem', 'einer', 'eines', 'einfach', 'einmal', 'erst', 'etwas', 'gerade', 'genau', 'gibt', 'haben',
        'heute', 'hier', 'immer', 'jetzt', 'jedoch', 'kann', 'können', 'machen', 'mache', 'macht', 'manchmal', 'mehr',
        'müssen', 'nämlich', 'noch', 'nicht', 'nichts', 'oder', 'schon', 'sehen', 'sehr', 'sein', 'seine', 'sich', 'sind',
        'sollen', 'sollte', 'sondern', 'unter', 'viele', 'warum', 'weil', 'welche', 'welcher', 'welches', 'wenn',
        'werden', 'wieder', 'wird', 'wollen', 'wurde', 'wurden', 'zwischen', 'ihnen', 'ihre', 'ihrer', 'eure', 'unsere',
        'schauen', 'sagen', 'sagt', 'gleich', 'ganz', 'dass', 'wisst', 'wissen', 'Frage', 'Fragen',
    ];

    public function analyse(Lesson $lesson, string $text): array
    {
        $sentences = $this->sentences($text);
        $terms = $this->keyTerms($sentences);

        $summary = implode(' ', $this->keySentences($sentences, $terms));
        $boardOutline = array_map(fn (string $term): string => $term.': '.$this->shorten($this->firstSentenceWith($sentences, $term), 110), $terms);
        $questions = array_slice(array_map($this->question(...), array_values(array_filter($sentences, fn (string $sentence): bool => str_ends_with($sentence, '?')))), 0, self::MAX_QUESTIONS);

        return [
            'summary' => $summary,
            'key_terms' => $terms,
            'board_outline' => $boardOutline,
            'class_questions' => $questions,
            'suggested_notes' => $this->notes($summary, $terms, $boardOutline, $questions),
        ];
    }

    /**
     * Sentences by punctuation; a stretch without any (recognised speech often has none) is cut into pieces of 25 words.
     *
     * @return list<string>
     */
    private function sentences(string $notes): array
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $notes));
        $sentences = [];

        foreach (preg_split('/(?<=[.!?])\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $sentence) {
            $words = explode(' ', $sentence);

            foreach (array_chunk($words, count($words) > 40 ? 25 : count($words)) as $piece) {
                $sentences[] = implode(' ', $piece);
            }
        }

        return $sentences;
    }

    /**
     * The most frequent significant words, most frequent first, ties alphabetically, in the spelling of their first use.
     * German nouns are capitalised in the middle of a sentence, so when enough of those exist, only they count.
     *
     * @param  list<string>  $sentences
     * @return list<string>
     */
    private function keyTerms(array $sentences): array
    {
        $counts = [];
        $spelling = [];
        $nouns = [];
        $stopWords = array_map(mb_strtolower(...), self::STOP_WORDS);

        foreach ($sentences as $sentence) {
            preg_match_all('/\p{L}[\p{L}-]{4,}/u', $sentence, $matches, PREG_OFFSET_CAPTURE);

            foreach ($matches[0] as [$word, $offset]) {
                $key = mb_strtolower($word);

                if (in_array($key, $stopWords, true)) {
                    continue;
                }

                $counts[$key] = ($counts[$key] ?? 0) + 1;
                $spelling[$key] ??= $word;
                $nouns[$key] = ($nouns[$key] ?? false) || ($offset > 0 && preg_match('/^\p{Lu}/u', $word) === 1);
            }
        }

        $nounCounts = array_intersect_key($counts, array_filter($nouns));
        $counts = count($nounCounts) >= self::KEY_TERMS ? $nounCounts : $counts;

        uksort($counts, fn (string $a, string $b): int => [$counts[$b], $a] <=> [$counts[$a], $b]);

        return array_map(fn (string $key): string => Str::ucfirst($spelling[$key]), array_slice(array_keys($counts), 0, self::KEY_TERMS));
    }

    /**
     * The sentences that mention the most key terms, in the order they were said.
     *
     * @param  list<string>  $sentences
     * @param  list<string>  $terms
     * @return list<string>
     */
    private function keySentences(array $sentences, array $terms): array
    {
        $scores = [];

        foreach ($sentences as $index => $sentence) {
            $lower = mb_strtolower($sentence);
            $score = 0;

            foreach ($terms as $position => $term) {
                $score += substr_count($lower, mb_strtolower($term)) * (count($terms) - $position);
            }

            // Questions are listed separately; short fragments carry little.
            $scores[$index] = str_ends_with($sentence, '?') || count(explode(' ', $sentence)) < 4 ? 0 : $score;
        }

        arsort($scores);
        $chosen = array_keys(array_slice(array_filter($scores), 0, self::SUMMARY_SENTENCES, true));
        sort($chosen);

        return array_map(fn (int $index): string => $sentences[$index], $chosen);
    }

    /**
     * @param  list<string>  $sentences
     */
    private function firstSentenceWith(array $sentences, string $term): string
    {
        foreach ($sentences as $sentence) {
            if (mb_stripos($sentence, $term) !== false) {
                return $sentence;
            }
        }

        return '';
    }

    /**
     * "Anna fragt: Warum ...?" is listed as the question itself.
     */
    private function question(string $sentence): string
    {
        $asked = trim(Str::afterLast($sentence, ': '));

        return count(explode(' ', $asked)) >= 3 ? $asked : $sentence;
    }

    private function shorten(string $sentence, int $limit): string
    {
        return Str::limit($sentence, $limit, '…', preserveWords: true);
    }

    /**
     * @param  list<string>  $terms
     * @param  list<string>  $boardOutline
     * @param  list<string>  $questions
     */
    private function notes(string $summary, array $terms, array $boardOutline, array $questions): string
    {
        return collect([
            $summary !== '' ? "Zusammenfassung:\n".$summary : null,
            $terms !== [] ? 'Kernbegriffe: '.implode(', ', $terms) : null,
            $boardOutline !== [] ? "Tafelbild:\n".implode("\n", array_map(fn (string $line): string => '- '.$line, $boardOutline)) : null,
            $questions !== [] ? "Fragen aus der Stunde:\n".implode("\n", array_map(fn (string $line): string => '- '.$line, $questions)) : null,
        ])->filter()->implode("\n\n");
    }
}
