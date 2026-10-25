<?php

namespace App\Console\Commands;

use App\Models\Answer;
use App\Models\ClassBookEntry;
use App\Models\ClassSubstitution;
use App\Models\ExplanationMessage;
use App\Models\ExplanationSession;
use App\Models\Lesson;
use App\Models\Question;
use App\Models\StudentQuestion;
use App\Models\Submission;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Storage limitation (Art. 5 and 17 DSGVO): deletes lessons and "Erklär's mir" conversations older than the
 * configured number of school years. A lesson expires only when everything about it is older than the cut-off
 * (lesson date, last change, due date, newest hand-in), so current work is never lost to a mistyped date.
 * Questions, students' questions to the teacher, hand-ins, answers, messages and homework assignments go with them through the foreign keys. Klassenbuch entries (they name absent students) outlive their lesson and expire by their own date in the same run. So do ended substitutions with their handover note, once the substitute holds no lesson in the class any more. Users, classes, schools and the competency framework are never touched.
 * Worksheet files go with their lesson. A file that no lesson points to any more (lesson, class or teacher account deleted, worksheet removed in the form) is swept up in the same run once it is a day old.
 */
#[Signature('lernplattform:prune-learning-data {--dry-run : Nur zählen, nichts löschen}')]
#[Description('Löscht Unterrichtsstunden und Erklär\'s-mir-Gespräche, die älter als die Aufbewahrungsfrist sind')]
class PruneLearningData extends Command
{
    public function handle(): int
    {
        $keptYears = (int) config('lernplattform.retention.school_years_kept');
        $starts = (string) config('lernplattform.retention.school_year_starts');
        $oneYearFrom = (string) config('lernplattform.retention.one_year_rule_from');

        // A broken setting must never turn into "delete everything".
        if (
            $keptYears < 1
            || ! preg_match('/^\d{2}-\d{2}$/', $starts) || ! checkdate((int) substr($starts, 0, 2), (int) substr($starts, 3), 2001)
            || ($oneYearFrom !== '' && ! CarbonImmutable::hasFormat($oneYearFrom, 'Y-m-d'))
        ) {
            $this->error('Ungültige Aufbewahrungs-Einstellung: school_years_kept muss mindestens 1 sein, school_year_starts das Format MM-TT und one_year_rule_from das Format JJJJ-MM-TT haben.');

            return self::FAILURE;
        }

        // Transition: the accepted agreements promised two school years, so one year only counts from the given date on.
        if ($keptYears === 1 && $oneYearFrom !== '' && CarbonImmutable::today()->lt(CarbonImmutable::parse($oneYearFrom))) {
            $keptYears = 2;
        }

        $cutoff = self::cutoffDate(CarbonImmutable::today(), $keptYears, $starts);
        $dryRun = (bool) $this->option('dry-run');

        // The lesson date is typed in by the teacher, so it alone must never expire a lesson: the row itself
        // (created_at <= updated_at), its due date and its newest hand-in have to be older than the cut-off too.
        $oldLessons = fn (): Builder => Lesson::query()
            ->whereDate('held_on', '<', $cutoff->toDateString())
            ->where('updated_at', '<', $cutoff)
            ->where(fn (Builder $query) => $query->whereNull('due_on')->orWhereDate('due_on', '<', $cutoff->toDateString()))
            ->whereDoesntHave('submissions', fn (Builder $query) => $query->where('updated_at', '>=', $cutoff));
        $oldSessions = fn (): Builder => ExplanationSession::query()->where('created_at', '<', $cutoff);
        $oldSubmissions = fn (): Builder => Submission::query()->whereIn('lesson_id', $oldLessons()->select('id'));
        // A Klassenbuch entry outlives its lesson, so it expires by its own date, and like a lesson only when it was not touched since either.
        $oldClassBookEntries = fn (): Builder => ClassBookEntry::query()->whereDate('held_on', '<', $cutoff->toDateString())->where('updated_at', '<', $cutoff);

        // An ended substitution grants nothing; it only still matters while its substitute has lessons in the class (LessonPolicy::viewResults() recognises a former substitute by it). So it goes after the Klassenbuch cut-off, but never ahead of those lessons: it is deleted before them, the run after they were.
        $oldSubstitutions = fn (): Builder => ClassSubstitution::query()
            ->whereDate('ends_on', '<', $cutoff->toDateString())
            ->where('updated_at', '<', $cutoff)
            ->whereNotExists(fn (QueryBuilder $lessons) => $lessons->from('lessons')
                ->whereColumn('lessons.school_class_id', 'class_substitutions.school_class_id')
                ->whereColumn('lessons.teacher_id', 'class_substitutions.substitute_id'));

        $files = $oldLessons()->whereNotNull('attachments')->pluck('attachments')->flatten()->filter(fn (mixed $path): bool => is_string($path))->unique();

        $strayWorksheets = $this->strayWorksheets();

        $counts = [
            'Unterrichtsstunden' => $oldLessons()->count(),
            'Fragen' => Question::query()->whereIn('lesson_id', $oldLessons()->select('id'))->count(),
            'Rückfragen' => StudentQuestion::query()->whereIn('lesson_id', $oldLessons()->select('id'))->count(),
            'Abgaben' => $oldSubmissions()->count(),
            'Antworten' => Answer::query()->whereIn('submission_id', $oldSubmissions()->select('id'))->count(),
            'Erklär\'s-mir-Gespräche' => $oldSessions()->count(),
            'Nachrichten' => ExplanationMessage::query()->whereIn('explanation_session_id', $oldSessions()->select('id'))->count(),
            'Klassenbuch-Einträge' => $oldClassBookEntries()->count(),
            'Vertretungen' => $oldSubstitutions()->count(),
            'Arbeitsblatt-Dateien' => $files->filter(fn (string $path): bool => Storage::disk('local')->exists($path))->count() + $strayWorksheets->count(),
        ];

        $this->info(($dryRun ? 'Probelauf, es wird nichts gelöscht. ' : '').'Aufbewahrt wird alles ab dem '.$cutoff->format('d.m.Y').'.');

        if (! $dryRun) {
            DB::transaction(function () use ($oldLessons, $oldSessions, $oldClassBookEntries, $oldSubstitutions): void {
                $oldSubstitutions()->delete();
                $oldSessions()->delete();
                $oldLessons()->delete();
                $oldClassBookEntries()->delete();
            });

            // After the commit, so a failed delete never leaves lessons without their worksheets or the other way round.
            Storage::disk('local')->delete([
                ...$files->reject(fn (string $path): bool => Lesson::query()->whereJsonContains('attachments', $path)->exists())->values()->all(),
                ...$strayWorksheets->all(),
            ]);

            Log::info('Lerndaten gelöscht', ['cutoff' => $cutoff->toDateString()] + $counts);
        }

        $this->table(['Gegenstand', $dryRun ? 'würde gelöscht' : 'gelöscht'], collect($counts)->map(fn (int $count, string $label): array => [$label, $count])->values()->all());

        return self::SUCCESS;
    }

    /**
     * Worksheet files no lesson points to. The day of grace spares an upload that is being saved right now.
     *
     * @return Collection<int, string>
     */
    private function strayWorksheets(): Collection
    {
        $disk = Storage::disk('local');
        $used = Lesson::query()->whereNotNull('attachments')->pluck('attachments')->flatten()->filter(fn (mixed $path): bool => is_string($path))->flip();
        $graceEnds = now()->subDay()->getTimestamp();

        return collect($disk->allFiles('worksheets'))
            ->reject(fn (string $path): bool => $used->has($path) || $disk->lastModified($path) > $graceEnds)
            ->values();
    }

    /**
     * First day of the oldest school year that is still kept: the start of the current school year
     * minus the other kept years. Anything dated before it is expired.
     */
    public static function cutoffDate(CarbonImmutable $today, int $keptYears, string $schoolYearStarts): CarbonImmutable
    {
        [$month, $day] = array_map('intval', explode('-', $schoolYearStarts));

        $currentStart = $today->setDate($today->year, $month, $day)->startOfDay();

        if ($currentStart->greaterThan($today)) {
            $currentStart = $currentStart->subYear();
        }

        return $currentStart->subYears($keptYears - 1);
    }
}
