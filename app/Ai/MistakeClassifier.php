<?php

namespace App\Ai;

use App\MistakeType;
use App\Models\Answer;
use App\Models\Question;

/**
 * Names the kind of mistake in an answer that is not quite right (Fehlerdiagnose). Only a hint: callers never change a
 * score because of it, and App\Support\MistakeDiagnosis asks only for answers below `lernplattform.mistakes.threshold`
 * and drops anything below `lernplattform.mistakes.min_confidence`.
 *
 * Bound in AppServiceProvider as SchoolAwareMistakeClassifier: the SdkMistakeClassifier (the AI's own reading from the
 * evaluation, no extra call) for a school that switched the AI on, otherwise the rule-based DemoMistakeClassifier. A
 * local classifier can be added as another implementation; it gets the question, the answer and nothing about the student.
 */
interface MistakeClassifier
{
    /**
     * @param  array{score: int, feedback: string, mistake_type?: MistakeType, mistake_confidence?: int}  $evaluation  the answer's result from LessonAssistant::evaluateSubmission()
     * @return MistakeClassification|null null when there is nothing to say: unsure, not applicable or failed
     */
    public function classify(Question $question, Answer $answer, array $evaluation): ?MistakeClassification;
}
