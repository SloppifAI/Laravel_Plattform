<?php

namespace App\Ai;

use App\MistakeType;
use App\Models\Competency;
use App\Models\ExplanationSession;
use App\Models\Lesson;
use App\Models\Question;
use App\Models\School;
use App\Models\Submission;
use Illuminate\Support\Collection;

/**
 * The LessonAssistant the application uses: every call goes to the SdkLessonAssistant when the school the call is about
 * has the AI switched on (School::aiEnabled(): platform switch, the school's opt-in and its complete checklist), and to
 * the offline DemoLessonAssistant otherwise, so nothing of a school without opt-in ever leaves the platform.
 */
class SchoolAwareLessonAssistant implements LessonAssistant
{
    public function __construct(private readonly SdkLessonAssistant $sdk, private readonly DemoLessonAssistant $demo) {}

    public function draftHomework(Lesson $lesson): array
    {
        return $this->assistantFor($lesson->schoolClass?->school)->draftHomework($lesson);
    }

    public function reviseSummary(Lesson $lesson): string
    {
        return $this->assistantFor($lesson->schoolClass?->school)->reviseSummary($lesson);
    }

    /**
     * The school is the one of the source lessons, without any lesson (a Förderaufgabe from competency alone) the one of the signed-in user.
     */
    public function draftPracticeTask(Competency $competency, string $subject, Collection $sourceLessons, array $gaps, ?MistakeType $mistakeType = null): array
    {
        $school = $sourceLessons->first()?->schoolClass?->school ?? auth()->user()?->school;

        return $this->assistantFor($school)->draftPracticeTask($competency, $subject, $sourceLessons, $gaps, $mistakeType);
    }

    public function evaluateSubmission(Submission $submission): array
    {
        return $this->assistantFor($submission->lesson->schoolClass?->school)->evaluateSubmission($submission);
    }

    public function differentiateQuestion(Question $question): array
    {
        return $this->assistantFor($question->lesson->schoolClass?->school)->differentiateQuestion($question);
    }

    public function simplify(string $text, Lesson $lesson): string
    {
        return $this->assistantFor($lesson->schoolClass?->school)->simplify($text, $lesson);
    }

    public function askFollowUp(ExplanationSession $session): string
    {
        return $this->assistantFor($session->student?->school)->askFollowUp($session);
    }

    public function assessExplanation(ExplanationSession $session): array
    {
        return $this->assistantFor($session->student?->school)->assessExplanation($session);
    }

    private function assistantFor(?School $school): LessonAssistant
    {
        return $school?->aiEnabled() ? $this->sdk : $this->demo;
    }
}
