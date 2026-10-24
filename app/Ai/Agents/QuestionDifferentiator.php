<?php

namespace App\Ai\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\HasStructuredOutput;

/**
 * Two variants of one homework question for the same Erwartungshorizont: an easier one with a hint (Einstieg) and a more demanding one (Profi).
 */
class QuestionDifferentiator extends LessonAgent implements HasStructuredOutput
{
    public function instructions(): string
    {
        return self::ROLE.' Du bekommst eine Hausaufgabenfrage und schreibst sie in zwei weiteren Schwierigkeitsstufen für die Schülerinnen und Schüler um. Einstieg (basis): kürzer und leichter formuliert, in einem einfachen Satz, mit einem kurzen Hinweis, wie man anfangen kann. Profi (plus): anspruchsvoller, die Frage verlangt zusätzlich eine Begründung, einen Zusammenhang oder die Übertragung auf eine neue Situation. Beide Stufen fragen nach demselben Thema und werden an demselben Erwartungshorizont gemessen wie die ursprüngliche Frage; er liegt dir bewusst nicht vor. Verrate in keiner Stufe die Antwort oder Teile davon, auch nicht im Hinweis. Behalte die Art der Frage (Verständnis, Anwendung oder Reflexion) bei. Schreibe jede Stufe als eigenständige Frage, die ohne die anderen verständlich ist.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'basis' => $schema->string()->required(),
            'plus' => $schema->string()->required(),
        ];
    }
}
