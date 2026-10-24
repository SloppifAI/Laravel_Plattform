<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Log;

/**
 * Storage limitation (Art. 5 and 17 DSGVO) for the bell: entries name a lesson topic, subject and class, so they are
 * deleted once they are read for `lernplattform.notifications.keep_read_days` days, or at the latest
 * `keep_days` days after they were created, and at once when their user no longer exists (deleted account, deleted
 * school). The table has no foreign key, so the orphans are swept here.
 */
#[Signature('lernplattform:prune-notifications')]
#[Description('Löscht alte Benachrichtigungen und die gelöschter Konten')]
class PruneNotifications extends Command
{
    public function handle(): int
    {
        $keepReadDays = (int) config('lernplattform.notifications.keep_read_days');
        $keepDays = (int) config('lernplattform.notifications.keep_days');

        // A broken setting must never turn into "delete everything".
        if ($keepReadDays < 1 || $keepDays < 1) {
            $this->error('Ungültige Aufbewahrungs-Einstellung: notifications.keep_read_days und notifications.keep_days müssen mindestens 1 sein.');

            return self::FAILURE;
        }

        $expired = DatabaseNotification::query()
            ->where('read_at', '<', now()->subDays($keepReadDays))
            ->orWhere('created_at', '<', now()->subDays($keepDays))
            ->delete();

        $orphaned = DatabaseNotification::query()
            ->where('notifiable_type', (new User)->getMorphClass())
            ->whereNotIn('notifiable_id', User::query()->select('id'))
            ->delete();

        Log::info('Benachrichtigungen gelöscht', ['abgelaufen' => $expired, 'ohne_konto' => $orphaned]);
        $this->info("Gelöscht: {$expired} abgelaufene, {$orphaned} ohne Konto.");

        return self::SUCCESS;
    }
}
