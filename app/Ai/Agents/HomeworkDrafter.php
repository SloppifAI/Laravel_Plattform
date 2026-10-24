<?php

namespace App\Ai\Agents;

use App\QuestionType;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\HasStructuredOutput;

/**
 * Summary and the five homework questions (two comprehension, two application, one reflection) for a held lesson.
 */
class HomeworkDrafter extends LessonAgent implements HasStructuredOutput
{
    public const int QUESTION_COUNT = 5;

    public function instructions(): string
    {
        return self::ROLE.' Aus den Notizen und Arbeitsblättern einer gehaltenen Stunde schreibst du eine verständliche Zusammenfassung für die Klasse (3 bis 5 Sätze) und entwirfst genau fünf Hausaufgabenfragen: zwei Verständnisfragen (comprehension), zwei Anwendungsfragen (application), die das Gelernte auf eine neue Situation übertragen, und eine Reflexionsfrage (reflection). Die Reflexionsfrage muss sich auf einen konkreten Moment der Stunde aus den Notizen beziehen (zum Beispiel einen Versuch, eine Diskussion oder ein Beispiel an der Tafel) und das Datum der Stunde nennen, damit eine KI ohne Unterrichtsbesuch sie nicht beantworten kann. Ordne jede Frage der passendsten Kompetenz aus der gegebenen Liste zu (competency_id) oder setze null, wenn keine passt. Der Erwartungshorizont (expected_answer) ist nur für die Lehrkraft bestimmt und beschreibt, was eine gute Antwort enthält. Hat die Lehrkraft schon eigene Fragen, wiederholst du sie nicht und fragst auch nicht dasselbe mit anderen Worten: Deine fünf Fragen ergänzen sie.';
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
                    'competency_id' => $schema->integer()->nullable()->required(),
                ]))
                ->min(self::QUESTION_COUNT)
                ->max(self::QUESTION_COUNT)
                ->required(),
        ];
    }
}
