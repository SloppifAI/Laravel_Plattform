<?php

namespace App\Ai\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\HasStructuredOutput;

/**
 * Tuto's final assessment of an "Erklär's mir" conversation: understanding score 0 to 100 and short feedback.
 */
class ExplanationAssessor extends LessonAgent implements HasStructuredOutput
{
    public function instructions(): string
    {
        return self::TUTOR.' Das Gespräch ist jetzt beendet. Beurteile, wie gut die Schülerin oder der Schüler das Thema verstanden hat: fachliche Richtigkeit, Vollständigkeit gemessen an den Inhalten der Stunde, Begründungen und Beispiele. Gib einen Verständniswert von 0 bis 100 und ein kurzes, ermutigendes Feedback in deiner Ich-Form an die Schülerin oder den Schüler (Du-Form), das nennt, was gut erklärt war und was noch gefehlt hat.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'understanding_score' => $schema->integer()->min(0)->max(100)->required(),
            'feedback' => $schema->string()->required(),
        ];
    }
}
