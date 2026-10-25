<?php

namespace App\Support;

use App\Ai\LessonAssistant;
use App\Models\Competency;
use App\Models\Lesson;
use App\Models\Question;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The "Hausaufgabe mit KI erstellen" step shared by the lesson page and "Heute": the assistant (the real AI or the demo,
 * depending on the school) drafts the questions from the saved notes, and the summary only while the lesson has none. The
 * draft replaces the AI questions the teacher left unedited; the teacher's own and edited questions stay in front. A
 * summary the lesson has (written by the teacher, set by a connected app or from an earlier draft) stays as it is, the
 * questions build on it; "Zusammenfassung neu erstellen" (reviseSummary()) writes a new one on top of it.
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
     * Whether the draft leaves the summary alone: the one in the database is used instead of writing another.
     */
    public static function keepsSummary(Lesson $lesson): bool
    {
        return filled($lesson->summary);
    }

    /**
     * The confirmation before a new draft replaces an existing one.
     */
    public static function confirmationText(Lesson $lesson): string
    {
        $kept = $lesson->questions()->where('ai_drafted', false)->count();

        return (match (true) {
            ! self::keepsSummary($lesson) => __('Die Zusammenfassung und die KI-Fragen werden durch einen neuen KI-Entwurf ersetzt.'),
            (bool) $lesson->summary_from_app => __('Die KI-Fragen werden durch einen neuen KI-Entwurf ersetzt. Die Zusammenfassung aus der App bleibt erhalten.'),
            default => __('Die KI-Fragen werden durch einen neuen KI-Entwurf ersetzt. Die Zusammenfassung bleibt erhalten.'),
        })
            .($kept === 0 ? '' : ' '.trans_choice(':count eigene oder bearbeitete Frage bleibt erhalten.|:count eigene oder bearbeitete Fragen bleiben erhalten.', $kept));
    }

    /**
     * Writes the summary anew from the saved notes and worksheets, building on the one the lesson has: what it says stays
     * unless the notes contradict it. The new text is the teacher's to check, so it no longer counts as the app's.
     *
     * @throws Throwable when the assistant fails; the lesson is left untouched then
     */
    public static function reviseSummary(Lesson $lesson): void
    {
        $lesson->forceFill(['summary' => app(LessonAssistant::class)->reviseSummary($lesson), 'summary_from_app' => false])->save();
    }

    /**
     * Drafts and stores the questions for the lesson, and the summary while it has none (keepsSummary()).
     *
     * @throws Throwable when the assistant fails; the lesson is left untouched then
     */
    public static function create(Lesson $lesson): void
    {
        $draft = app(LessonAssistant::class)->draftHomework($lesson);

        // AI output is untrusted: an unknown competency would break the foreign key, so it is dropped instead.
        $allowedCompetencyIds = Competency::forSubject($lesson->subject)->pluck('id');

        DB::transaction(function () use ($lesson, $draft, $allowedCompetencyIds): void {
            if (! self::keepsSummary($lesson)) {
                $lesson->update(['summary' => $draft['summary']]);
            }
            // The callers only draft unreleased homework, so there are no answers; the check is the backstop.
            $lesson->questions()->where('ai_drafted', true)->whereDoesntHave('answers')->delete();

            $kept = $lesson->questions()->orderBy('id')->get();
            $kept->each(fn (Question $question, int $index) => $question->update(['position' => $index + 1]));

            $lesson->questions()->createMany(collect($draft['questions'])->values()->map(fn (array $question, int $index): array => [
                'type' => $question['type'],
                'prompt' => $question['prompt'],
                'expected_answer' => $question['expected_answer'],
                'competency_id' => $allowedCompetencyIds->contains($question['competency_id'] ?? null) ? $question['competency_id'] : null,
                'position' => $kept->count() + $index + 1,
                'ai_drafted' => true,
            ])->all());
        });
    }
}
