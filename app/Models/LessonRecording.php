<?php

namespace App\Models;

use App\TranscriptStatus;
use Database\Factories\LessonRecordingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * An audio-only recording of a lesson, visible to the lesson's teacher alone (LessonRecordingPolicy).
 * The file lives on the private 'local' disk and is streamed through an authorised route; it is never sent to an AI.
 * Rows and files expire after `lernplattform.recordings.keep_days` (php artisan lernplattform:prune-recordings).
 */
#[Fillable(['lesson_id', 'teacher_id', 'path', 'mime_type', 'chunks_received', 'duration_seconds', 'consent_confirmed_at', 'started_at', 'finished_at', 'transcript', 'transcript_status', 'transcript_source', 'analysis', 'analysed_at'])]
class LessonRecording extends Model
{
    /** @use HasFactory<LessonRecordingFactory> */
    use HasFactory;

    /**
     * Container types the browsers' MediaRecorder produces, with the file extension used for them.
     *
     * @var array<string, string>
     */
    public const array MIME_TYPES = ['audio/webm' => 'webm', 'audio/ogg' => 'ogg', 'audio/mp4' => 'm4a'];

    public const string SOURCE_ON_DEVICE = 'on_device';

    public const string SOURCE_SERVER = 'server';

    public const string SOURCE_MANUAL = 'manual';

    /**
     * A recording that is not finished counts as running while its last sign of life (a chunk, or the recorder's
     * heartbeat every 15 seconds, also while paused) is younger than this. The sign of life is `updated_at`, so code
     * that edits a recording other than the recorder's own requests must not touch it (see RecordLesson::store()).
     */
    public const int LIVE_SECONDS = 90;

    protected static function booted(): void
    {
        // Rows removed by a foreign key cascade (lesson, teacher) skip this; the prune command sweeps those files.
        static::deleted(fn (self $recording): bool => self::disk()->delete($recording->path));
    }

    public static function disk(): FilesystemAdapter
    {
        return Storage::disk('local');
    }

    /**
     * Where a new recording's file goes: random name, grouped by lesson.
     */
    public static function newPath(Lesson $lesson, string $mimeType): string
    {
        return 'recordings/'.$lesson->getKey().'/'.strtolower((string) Str::ulid()).'.'.self::MIME_TYPES[$mimeType];
    }

    /**
     * @return BelongsTo<Lesson, $this>
     */
    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function teacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }

    public function isFinished(): bool
    {
        return $this->finished_at !== null;
    }

    /**
     * Whether the teacher's browser is still recording this one. The page keeps such a recording out of reach of the
     * delete button and the player, because the file is still being written.
     */
    public function isLive(): bool
    {
        return ! $this->isFinished() && $this->updated_at->gt(now()->subSeconds(self::LIVE_SECONDS));
    }

    public function hasAudio(): bool
    {
        return self::disk()->exists($this->path);
    }

    /**
     * "12:34" or "1:02:03"; null while the duration is unknown (the recording was cut off).
     */
    public function durationLabel(): ?string
    {
        if ($this->duration_seconds === null) {
            return null;
        }

        $seconds = $this->duration_seconds;

        return $seconds >= 3600
            ? sprintf('%d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60)
            : sprintf('%d:%02d', intdiv($seconds, 60), $seconds % 60);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'consent_confirmed_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'analysed_at' => 'datetime',
            'analysis' => 'array',
            'transcript_status' => TranscriptStatus::class,
        ];
    }
}
