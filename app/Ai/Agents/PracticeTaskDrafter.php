<?php

namespace App\Ai\Agents;

use App\QuestionType;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\HasStructuredOutput;

/**
 * Three or four practice questions on one competency for students who showed a gap (a Förderaufgabe).
 */
class PracticeTaskDrafter extends LessonAgent implements HasStructuredOutput
{
    public const int MAX_QUESTIONS = 4;

    public function instructions(): string
    {
        return self::ROLE.' Für Schülerinnen und Schüler, bei denen sich in der Hausaufgabe eine Lücke gezeigt hat, entwirfst du eine Förderaufgabe zu genau einer Kompetenz: eine kurze Zusammenfassung (2 bis 4 Sätze), die ermutigend sagt, was geübt wird, und drei bis vier Übungsfragen, die von leicht zu schwer aufgebaut sind. Die erste Frage ist eine Verständnisfrage (comprehension), genau eine Frage ist eine Anwendungsfrage (application), die das Gelernte auf eine neue Situation überträgt. Reflexionsfragen gibt es nicht. Alle Fragen üben die genannte Kompetenz und knüpfen an die Inhalte der genannten Stunden an. Greife die Hinweise auf Lücken auf, ohne sie wörtlich zu zitieren, und formuliere ermutigend und konkret. Der Erwartungshorizont (expected_answer) ist nur für die Lehrkraft bestimmt und beschreibt, was eine gute Antwort enthält.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'summary' => $schema->string()->required(),
            'questions' => $schema->array()
                ->items($schema->object(fn (JsonSchema $schema): array => [
                    'type' => $schema->string()->enum(array_column(QuestionType::cases(), 'value'))->required(),
                    'prompt' => $schema->string()->required(),
                    'expected_answer' => $schema->string()->required(),
                ]))
                ->min(3)
                ->max(self::MAX_QUESTIONS)
                ->required(),
        ];
    }
}
