<?php

namespace App\Console\Commands;

use App\Models\LessonRecording;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Storage limitation for Tuto-Mikro: sessions are deleted `lernplattform.recordings.session_keep_days` days after they
 * started, and every file under `recordings/` on the private disk is removed. Tuto-Mikro writes no sound to any disk
 * (a segment is recognised from memory), so a file there is a leftover of the former recording mode, which stored audio.
 */
#[Signature('lernplattform:prune-recordings {--dry-run : Nur zählen, nichts löschen}')]
#[Description('Löscht alte Tuto-Mikro-Sitzungen und liegengebliebene Ton-Dateien')]
class PruneLessonRecordings extends Command
{
    public function handle(): int
    {
        $keepDays = (int) config('lernplattform.recordings.session_keep_days', 365);

        // A broken setting must never turn into "delete everything".
        if ($keepDays < 1) {
            $this->error('Ungültige Aufbewahrungs-Einstellung: recordings.session_keep_days muss mindestens 1 sein.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $expired = LessonRecording::query()->where('started_at', '<', now()->subDays($keepDays));
        $expiredCount = $expired->count();
        $stray = LessonRecording::disk()->allFiles('recordings');

        if (! $dryRun) {
            $expired->delete();
            LessonRecording::disk()->delete($stray);
            Log::info('Tuto-Mikro aufgeräumt', ['keep_days' => $keepDays, 'sitzungen' => $expiredCount, 'liegengebliebene_dateien' => count($stray)]);
        }

        $this->info(($dryRun ? 'Probelauf, es wird nichts gelöscht. ' : '')."Sitzungen werden {$keepDays} Tage aufbewahrt, Ton wird nicht gespeichert.");
        $this->table(['Gegenstand', $dryRun ? 'würde gelöscht' : 'gelöscht'], [['Sitzungen', $expiredCount], ['Liegengebliebene Ton-Dateien', count($stray)]]);

        return self::SUCCESS;
    }
}
