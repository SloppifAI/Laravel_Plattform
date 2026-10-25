<?php

namespace App\Ai;

use App\Models\Answer;
use App\Models\Question;

/**
 * The MistakeClassifier the application uses, decided like SchoolAwareLessonAssistant: the AI's reading for a school
 * that has the AI switched on (School::aiEnabled()), the offline rules otherwise.
 */
class SchoolAwareMistakeClassifier implements MistakeClassifier
{
    public function __construct(private readonly SdkMistakeClassifier $sdk, private readonly DemoMistakeClassifier $demo) {}

    public function classify(Question $question, Answer $answer, array $evaluation): ?MistakeClassification
    {
        $classifier = $question->lesson->schoolClass?->school?->aiEnabled() ? $this->sdk : $this->demo;

        return $classifier->classify($question, $answer, $evaluation);
    }
}
