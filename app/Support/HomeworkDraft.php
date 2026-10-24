<?php

namespace App\Support;

use App\Ai\LessonAssistant;
use App\Models\Competency;
use App\Models\Lesson;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The "Hausaufgabe mit KI erstellen" step shared by the lesson page and "Heute": the assistant (the real AI or the demo,
 * depending on the school) drafts summary and questions from the saved notes, and the draft replaces what the lesson had.
 */
final class HomeworkDraft
{
    /**
     * Whether the draft comes from the school's real AI, not from the offline demo.
     */
    public static function isRealAi(Lesson $lesson): bool
    {
        return $lesson->schoolClass->school?->aiEnabled() ?? false;
    }

    /**
     * Drafts and stores summary and questions for the lesson.
     *
     * @throws Throwable when the assistant fails; the lesson is left untouched then
     */
    public static function create(Lesson $lesson): void
    {
        $draft = app(LessonAssistant::class)->draftHomework($lesson);

        // AI output is untrusted: an unknown competency would break the foreign key, so it is dropped instead.
        $allowedCompetencyIds = Competency::forSubject($lesson->subject)->pluck('id');

        DB::transaction(function () use ($lesson, $draft, $allowedCompetencyIds): void {
            $lesson->update(['summary' => $draft['summary']]);
            $lesson->questions()->delete();
            $lesson->questions()->createMany(collect($draft['questions'])->values()->map(fn (array $question, int $index): array => [
                'type' => $question['type'],
                'prompt' => $question['prompt'],
                'expected_answer' => $question['expected_answer'],
                'competency_id' => $allowedCompetencyIds->contains($question['competency_id'] ?? null) ? $question['competency_id'] : null,
                'position' => $index + 1,
            ])->all());
        });
    }
}
