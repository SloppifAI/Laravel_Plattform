<?php

namespace App\Ai;

use App\Models\Competency;
use App\Models\ExplanationSession;
use App\Models\Lesson;
use App\Models\Question;
use App\Models\Submission;
use App\QuestionType;
use Illuminate\Support\Collection;

/**
 * Everything the platform asks an AI to do. Implementations only compute results; callers persist them.
 *
 * Bound in AppServiceProvider as SchoolAwareLessonAssistant: the SdkLessonAssistant (Laravel AI SDK) for a school that
 * switched the AI on (School::aiEnabled()), otherwise the offline DemoLessonAssistant, so the platform works without any API call.
 */
interface LessonAssistant
{
    /**
     * Summarise a lesson from the teacher's notes and worksheets and draft exactly five homework questions:
     * two comprehension, two application and one reflection question, each mapped to one of the competencies
     * returned by Competency::forSubject($lesson->subject). The lesson's own and edited questions (`ai_drafted` false)
     * stay next to the draft (see HomeworkDraft), so the draft does not repeat them; the offline demo leaves out the
     * questions it would repeat instead of finding others.
     *
     * @return array{summary: string, questions: list<array{type: QuestionType, prompt: string, expected_answer: string, competency_id: int|null}>}
     */
    public function draftHomework(Lesson $lesson): array;

    /**
     * Draft a Förderaufgabe: three or four practice questions on one competency, from easier to harder with one
     * application question, for students who showed gaps in the given lessons. `$gaps` are the anonymous hints the AI
     * named when it evaluated their homework. Every question is mapped to `$competency`; the summary says what is practised.
     *
     * @param  Collection<int, Lesson>  $sourceLessons  the lessons in which the gap showed
     * @param  list<string>  $gaps
     * @return array{summary: string, questions: list<array{type: QuestionType, prompt: string, expected_answer: string, competency_id: int}>}
     */
    public function draftPracticeTask(Competency $competency, string $subject, Collection $sourceLessons, array $gaps): array;

    /**
     * Check a student's own summary and answers (`$submission->answers`) against the lesson.
     * Scores are 0–100.
     *
     * @return array{understanding_score: int, feedback: string, gaps: list<string>, answers: array<int, array{score: int, feedback: string}>} answers keyed by question id
     */
    public function evaluateSubmission(Submission $submission): array;

    /**
     * Two more levels of a homework question for students who need an easier or a more demanding one: `basis`
     * (Einstieg) is shorter and comes with a hint, `plus` (Profi) asks for a reason or a transfer. Both are measured
     * against the same Erwartungshorizont, which is never sent to the AI nor revealed in the texts.
     *
     * @return array{basis: string, plus: string}
     */
    public function differentiateQuestion(Question $question): array;

    /**
     * The text of a lesson (its summary or a question, written by the teacher) in Einfache Sprache: short sentences,
     * everyday words, same content. Students' own texts are never passed here.
     */
    public function simplify(string $text, Lesson $lesson): string;

    /**
     * The tutor's next follow-up question after the student's latest explanation in the session.
     */
    public function askFollowUp(ExplanationSession $session): string;

    /**
     * Final assessment of how well the student explained the topic, once they end the session.
     *
     * @return array{understanding_score: int, feedback: string}
     */
    public function assessExplanation(ExplanationSession $session): array;
}
