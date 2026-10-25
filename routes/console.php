<?php

use App\Models\AppAuthorizationCode;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Unused password reset tokens would otherwise stay in the database forever.
Schedule::command('auth:clear-resets')->daily();

// Tokens of connected apps (config sanctum.expiration, 180 days) are deleted a day after they expired.
Schedule::command('sanctum:prune-expired --hours=24')->daily();

// One-time codes of the app authorisation (/app/verbinden) are spent or expire after five minutes: nothing left to keep.
Schedule::command('model:prune', ['--model' => [AppAuthorizationCode::class]])->daily();

// Lessons and "Erklär's mir" conversations older than the retention period (config/lernplattform.php) are deleted.
// Daily and idempotent: a missed run only postpones the deletion, and the cut-off moves once a year.
Schedule::command('lernplattform:prune-learning-data')->dailyAt('03:30')->withoutOverlapping();

// Lesson recordings are audio of a classroom: deleted after recordings.keep_days (default 14), including orphaned files.
Schedule::command('lernplattform:prune-recordings')->dailyAt('03:45')->withoutOverlapping();

// Bell entries name lesson topics: read ones go after notifications.keep_read_days, all after notifications.keep_days, orphans at once.
Schedule::command('lernplattform:prune-notifications')->dailyAt('04:00')->withoutOverlapping();
