<?php

namespace App\Console\Commands;

use App\Models\LessonRecording;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Storage limitation for lesson recordings: audio is deleted `lernplattform.recordings.keep_days` days after the
 * recording started, file and row together. Files whose row is gone (the lesson, the teacher or the school was
 * deleted, which removes the rows through foreign keys but not the files) are swept as well.
 */
#[Signature('lernplattform:prune-recordings {--dry-run : Nur zählen, nichts löschen}')]
#[Description('Löscht Unterrichtsaufnahmen, die älter als die Aufbewahrungsfrist sind, und verwaiste Audiodateien')]
class PruneLessonRecordings extends Command
{
    /**
     * Seconds a file without a row must be untouched before it counts as orphaned, so a recording that is just
     * being created is never swept.
     */
    private const int ORPHAN_GRACE_SECONDS = 86400;

    public function handle(): int
    {
        $keepDays = (int) config('lernplattform.recordings.keep_days', 14);

        // A broken setting must never turn into "delete everything".
        if ($keepDays < 1) {
            $this->error('Ungültige Aufbewahrungs-Einstellung: recordings.keep_days muss mindestens 1 sein.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $expired = fn () => LessonRecording::query()->where('started_at', '<', now()->subDays($keepDays));
        $expiredCount = $expired()->count();

        if (! $dryRun) {
            // One by one, so the model deletes the audio file with the row.
            $expired()->lazyById()->each(fn (LessonRecording $recording): ?bool => $recording->delete());
        }

        $known = LessonRecording::query()->pluck('path')->flip();
        $orphans = collect(LessonRecording::disk()->allFiles('recordings'))
            ->reject(fn (string $path): bool => $known->has($path) || LessonRecording::disk()->lastModified($path) > now()->subSeconds(self::ORPHAN_GRACE_SECONDS)->getTimestamp());

        if (! $dryRun) {
            LessonRecording::disk()->delete($orphans->all());
            Log::info('Aufnahmen gelöscht', ['keep_days' => $keepDays, 'aufnahmen' => $expiredCount, 'verwaiste_dateien' => $orphans->count()]);
        }

        $this->info(($dryRun ? 'Probelauf, es wird nichts gelöscht. ' : '')."Aufnahmen werden {$keepDays} Tage aufbewahrt.");
        $this->table(['Gegenstand', $dryRun ? 'würde gelöscht' : 'gelöscht'], [['Aufnahmen', $expiredCount], ['Verwaiste Audiodateien', $orphans->count()]]);

        return self::SUCCESS;
    }
}
