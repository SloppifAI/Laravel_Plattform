<?php

namespace App\Ai;

use App\MistakeSource;
use App\MistakeType;
use App\Models\Answer;
use App\Models\Question;

/**
 * The AI's own reading of the mistake: SdkLessonAssistant::evaluateSubmission() asks for it in the same request that
 * scores the answer, so there is no extra call, nothing more is sent and nothing waits for it. Without a valid type
 * from the AI there is no mistake type.
 */
class SdkMistakeClassifier implements MistakeClassifier
{
    public function classify(Question $question, Answer $answer, array $evaluation): ?MistakeClassification
    {
        $type = $evaluation['mistake_type'] ?? null;

        return $type instanceof MistakeType
            ? new MistakeClassification($type, max(0, min(100, (int) ($evaluation['mistake_confidence'] ?? 0))) / 100, MistakeSource::Ai)
            : null;
    }
}
