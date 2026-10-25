<?php

namespace App\Support;

use App\Ai\MistakeClassification;
use App\Ai\MistakeClassifier;
use App\MistakeSource;
use App\MistakeType;
use App\Models\Answer;
use App\Models\Submission;
use Illuminate\Support\Facades\DB;

/**
 * Fehlerdiagnose: the kind of mistake of the answers that are not quite right, as a hint for the student, the teacher's
 * learning progress page and the Förderaufgabe. Never a grade: no score, understanding score or support threshold
 * reads it, and the parent report does not show it.
 */
class MistakeDiagnosis
{
    /**
     * Stores the mistake types after an evaluation (fail closed): an answer at or above `mistakes.threshold` gets none, a
     * reading less sure than `mistakes.min_confidence` is dropped, a failing classifier leaves the type empty, and a
     * teacher's correction stays. Call it after the evaluation is saved, so nothing here can undo or delay it.
     *
     * @param  array<int, array{score: int, feedback: string, mistake_type?: MistakeType, mistake_confidence?: int}>  $evaluatedAnswers  keyed by question id, from LessonAssistant::evaluateSubmission()
     */
    public static function record(Submission $submission, array $evaluatedAnswers): void
    {
        $classifier = app(MistakeClassifier::class);

        foreach ($submission->answers()->with('question.lesson.schoolClass.school')->get() as $answer) {
            $evaluation = $evaluatedAnswers[$answer->question_id] ?? null;

            if ($evaluation === null || $answer->mistake_source === MistakeSource::Teacher) {
                continue;
            }

            $classification = rescue(fn (): ?MistakeClassification => self::classify($classifier, $answer, $evaluation));

            rescue(fn () => $answer->update([
                'mistake_type' => $classification?->type,
                // Only an AI says how sure it is; the demo rules and teachers do not.
                'mistake_confidence' => $classification === null || $classification->source === MistakeSource::Demo ? null : (int) round($classification->confidence * 100),
                'mistake_source' => $classification?->source,
            ]));
        }
    }

    /**
     * How often each mistake type occurred per competency in the answers of the given students, most frequent first.
     * The callers pass only students the viewer may see (LearningInsights::visibleStudents()).
     *
     * @param  array<int>  $studentIds
     * @param  array<int>|null  $lessonIds  only answers to these lessons, null for all
     * @return array<int, array<string, int>> competency id => mistake type value => count
     */
    public static function counts(array $studentIds, ?array $lessonIds = null): array
    {
        return DB::table('answers')
            ->join('submissions', 'submissions.id', '=', 'answers.submission_id')
            ->join('questions', 'questions.id', '=', 'answers.question_id')
            ->whereIn('submissions.student_id', $studentIds)
            ->when($lessonIds !== null, fn ($query) => $query->whereIn('submissions.lesson_id', $lessonIds))
            ->whereNotNull('answers.mistake_type')
            ->whereNotNull('questions.competency_id')
            ->groupBy('questions.competency_id', 'answers.mistake_type')
            ->orderByDesc('total')
            ->orderBy('answers.mistake_type')
            ->get(['questions.competency_id', 'answers.mistake_type', DB::raw('count(*) as total')])
            ->groupBy('competency_id')
            ->map(fn ($rows): array => $rows->mapWithKeys(fn (object $row): array => [$row->mistake_type => (int) $row->total])->all())
            ->all();
    }

    /**
     * The most frequent mistake type of the students in one competency, in the given lessons.
     *
     * @param  array<int>  $studentIds
     * @param  array<int>  $lessonIds
     */
    public static function mostFrequent(array $studentIds, array $lessonIds, int $competencyId): ?MistakeType
    {
        return MistakeType::tryFrom((string) array_key_first(self::counts($studentIds, $lessonIds)[$competencyId] ?? []));
    }

    /**
     * @param  array{score: int, feedback: string, mistake_type?: MistakeType, mistake_confidence?: int}  $evaluation
     */
    private static function classify(MistakeClassifier $classifier, Answer $answer, array $evaluation): ?MistakeClassification
    {
        if ($evaluation['score'] >= (int) config('lernplattform.mistakes.threshold')) {
            return null;
        }

        $classification = $classifier->classify($answer->question, $answer, $evaluation);

        return $classification !== null && $classification->confidence >= (float) config('lernplattform.mistakes.min_confidence')
            ? $classification
            : null;
    }
}
