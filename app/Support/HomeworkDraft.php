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
 * depending on the school) drafts summary and questions from the saved notes. The draft replaces the summary and the
 * AI questions the teacher left unedited; the teacher's own and edited questions stay in front. A summary a connected app
 * set (`summary_from_app`) stays as well: the assistant still reads it, but only its questions are used.
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
     * Whether the draft leaves the summary alone because a connected app set it.
     */
    public static function keepsSummary(Lesson $lesson): bool
    {
        return $lesson->summary_from_app && filled($lesson->summary);
    }

    /**
     * The confirmation before a new draft replaces an existing one.
     */
    public static function confirmationText(Lesson $lesson): string
    {
        $kept = $lesson->questions()->where('ai_drafted', false)->count();

        return (self::keepsSummary($lesson)
            ? __('Die KI-Fragen werden durch einen neuen KI-Entwurf ersetzt. Die Zusammenfassung aus der App bleibt erhalten.')
            : __('Die Zusammenfassung und die KI-Fragen werden durch einen neuen KI-Entwurf ersetzt.'))
            .($kept === 0 ? '' : ' '.trans_choice(':count eigene oder bearbeitete Frage bleibt erhalten.|:count eigene oder bearbeitete Fragen bleiben erhalten.', $kept));
    }

    /**
     * Drafts and stores summary and questions for the lesson; an app's summary stays (keepsSummary()).
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
