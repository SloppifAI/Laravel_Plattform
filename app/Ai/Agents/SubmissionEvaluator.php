<?php

namespace App\Ai\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\HasStructuredOutput;

/**
 * Checks a student's own summary and answers against the lesson: scores 0 to 100, feedback and up to three gaps.
 */
class SubmissionEvaluator extends LessonAgent implements HasStructuredOutput
{
    public const int MAX_GAPS = 3;

    public function instructions(): string
    {
        return self::ROLE.' Du prüfst Hausaufgaben darauf, ob die Schülerin oder der Schüler die Stunde verstanden hat. Vergleiche die eigene Zusammenfassung und die Antworten mit den Notizen der Lehrkraft und dem Erwartungshorizont. Reflexionsfragen haben keine einzig richtige Antwort: Bewerte dort den Bezug zur Stunde, eigene Gedanken und Begründungen. Alle Punktzahlen liegen zwischen 0 und 100. Das Feedback ist ermutigend, konkret und richtet sich direkt an die Schülerin oder den Schüler. Nenne in gaps höchstens drei kurze Hinweise, was noch einmal wiederholt werden sollte. Zitiere den Erwartungshorizont nicht wörtlich. Bei manchen Fragen steht eine Stufe, die die Schülerin oder der Schüler gewählt hat: „Einstieg“ ist eine leichtere Fassung der Frage mit Hinweis, „Standard“ die ursprüngliche Frage, „Profi“ eine anspruchsvollere Fassung, die zusätzlich eine Begründung oder Übertragung verlangt. Der Erwartungshorizont und der Maßstab für die Punkte sind für alle Stufen derselbe: Bewerte jede Antwort am vollen Erwartungshorizont, damit gleich gute Antworten gleich viele Punkte bekommen, egal welche Stufe gewählt wurde. Die Stufe zeigt dir nur, welche Fassung der Frage die Schülerin oder der Schüler gesehen hat: Sie ist weder ein Grund für Punktabzug noch für einen Zuschlag. Fehlt bei „Profi“ eine Begründung oder Übertragung, oder fehlen bei „Einstieg“ weitere Punkte des Erwartungshorizonts, sag das im Feedback freundlich als Tipp. Die Schülerin oder der Schüler hat die Zusammenfassung der Lehrkraft gelesen, bevor sie oder er geantwortet hat: Passagen, die wörtlich oder fast wörtlich daraus abgeschrieben sind, zeigen kein Verständnis und bringen keine Punkte. Weise im Feedback freundlich darauf hin, dass du die eigenen Worte sehen möchtest.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'understanding_score' => $schema->integer()->min(0)->max(100)->required(),
            'feedback' => $schema->string()->required(),
            'gaps' => $schema->array()->items($schema->string())->max(self::MAX_GAPS)->required(),
            'answers' => $schema->array()
                ->items($schema->object(fn (JsonSchema $schema): array => [
                    'question_id' => $schema->integer()->required(),
                    'score' => $schema->integer()->min(0)->max(100)->required(),
                    'feedback' => $schema->string()->required(),
                ]))
                ->required(),
        ];
    }
}
