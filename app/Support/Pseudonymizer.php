<?php

namespace App\Support;

use App\Models\Lesson;

/**
 * Replaces the names of a lesson's students and its teacher in a text with neutral labels ("Schüler:in A",
 * "Lehrkraft"), so a transcript can be handed to an AI without naming anyone.
 *
 * Full names, first names and last names are replaced, case-insensitively, as whole words and with a genitive "s"
 * ("Annas Heft"). A name part shared by several people becomes "Person". Limits: names of people who are not in the
 * class (parents, other teachers) and nicknames are not known and stay in the text; speech recognition that
 * misspells a name defeats the match. The teacher therefore reviews the transcript before every evaluation.
 */
final class Pseudonymizer
{
    public const string TEACHER_LABEL = 'Lehrkraft';

    private const string STUDENT_LABEL = 'Schüler:in';

    private const string AMBIGUOUS_LABEL = 'Person';

    /**
     * Name particles ("Ben van der Berg") are common words, not names, so they are not replaced on their own.
     * Whatever else a name consists of is, whether it is written in capitals or not ("anna schmidt", "Müller, Jonas").
     *
     * @var list<string>
     */
    private const array PARTICLES = ['von', 'van', 'de', 'der', 'den', 'zu', 'zur', 'vom', 'di', 'da', 'le', 'la', 'el', 'bin', 'ibn'];

    /**
     * @param  array<string, string>  $labels  lower-case name (one part or the full name) => label
     */
    private function __construct(private readonly array $labels) {}

    /**
     * The students of the lesson's class (labelled A, B, C ... in the order of their ids) and the lesson's teacher.
     */
    public static function forLesson(Lesson $lesson): self
    {
        return self::forPeople(
            $lesson->schoolClass->students()->orderBy('id')->pluck('name')->all(),
            $lesson->teacher?->name,
        );
    }

    /**
     * @param  list<string>  $studentNames
     */
    public static function forPeople(array $studentNames, ?string $teacherName): self
    {
        $labels = [];

        $add = function (string $name, string $label) use (&$labels): void {
            $name = self::normalise($name);

            if ($name === '') {
                return;
            }

            $parts = array_filter(
                (array) preg_split('/[\s,;.]+/u', $name, -1, PREG_SPLIT_NO_EMPTY),
                fn (string $part): bool => mb_strlen($part) >= 2 && ! in_array(mb_strtolower($part), self::PARTICLES, true),
            );

            foreach ([$name, ...$parts] as $entry) {
                $key = mb_strtolower($entry);
                $labels[$key] = isset($labels[$key]) && $labels[$key] !== $label ? self::AMBIGUOUS_LABEL : $label;
            }
        };

        foreach (array_values($studentNames) as $index => $name) {
            $add($name, self::STUDENT_LABEL.' '.($index < 26 ? chr(65 + $index) : $index + 1));
        }

        if ($teacherName !== null) {
            $add($teacherName, self::TEACHER_LABEL);
        }

        return new self($labels);
    }

    public function apply(string $text): string
    {
        if ($this->labels === []) {
            return $text;
        }

        // Longest first, so "Anna Müller" wins over "Anna".
        $names = array_keys($this->labels);
        usort($names, fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

        $alternatives = implode('|', array_map(fn (string $name): string => str_replace(' ', '\s+', preg_quote($name, '/')), $names));

        return (string) preg_replace_callback(
            '/(?<![\p{L}\p{N}])('.$alternatives.')(?:[\'’]?s)?(?![\p{L}\p{N}])/iu',
            fn (array $match): string => $this->labels[mb_strtolower(self::normalise($match[1]))] ?? self::AMBIGUOUS_LABEL,
            $text,
        );
    }

    private static function normalise(string $name): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $name));
    }
}
