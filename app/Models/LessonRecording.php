<?php

namespace App\Models;

use App\Support\RecordingConsentGate;
use App\Support\RecordingConsentResult;
use Database\Factories\LessonRecordingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;

/**
 * One session of "Tuto-Mikro" in a lesson: the teacher's microphone was on from `started_at` to `finished_at`.
 *
 * The row holds session data only, as proof of what was checked: who led it, who was present (the ids the teacher
 * marked and when the teacher confirmed that list), the version of the consent form and which purposes were checked,
 * and whether sound segments went to the AI provider's speech recognition. There is no audio and no text: a segment
 * exists only in the memory of the request that recognises it (it is never written to a disk), and the recognised
 * text lives in the teacher's browser until the teacher takes something over into the lesson notes. Rows expire after
 * `lernplattform.recordings.session_keep_days` (php artisan lernplattform:prune-recordings).
 */
#[Fillable(['lesson_id', 'teacher_id', 'started_at', 'finished_at', 'duration_seconds', 'present_student_ids', 'attendance_confirmed_at', 'consent_form_version', 'purposes', 'audio_sent_to_ai'])]
class LessonRecording extends Model
{
    /** @use HasFactory<LessonRecordingFactory> */
    use HasFactory;

    /**
     * Container types the browsers' MediaRecorder produces, with the file extension that goes with them.
     *
     * @var array<string, string>
     */
    public const array MIME_TYPES = ['audio/webm' => 'webm', 'audio/ogg' => 'ogg', 'audio/mp4' => 'm4a'];

    /**
     * The disk that held the sound of the former recording mode. Nothing is written there any more; the prune command
     * still sweeps what is left.
     */
    public static function disk(): FilesystemAdapter
    {
        return Storage::disk('local');
    }

    /**
     * What a session is about to process, in the words of the consent form: the sound and the text always, and the
     * AI analysis when the school's AI is on (the sound then goes to the provider for recognition, the text for the summary).
     *
     * @return list<string>
     */
    public static function purposesFor(School $school): array
    {
        return ['audio', 'transcript', ...($school->aiEnabled() ? ['ai_analysis'] : [])];
    }

    /**
     * The consent check in front of a session and in front of every later step that sends something to the AI.
     *
     * @param  iterable<User>  $presentStudents
     */
    public static function consentCheck(Lesson $lesson, User $teacher, iterable $presentStudents): RecordingConsentResult
    {
        return RecordingConsentGate::check($lesson->schoolClass, $presentStudents, $teacher, self::purposesFor($lesson->schoolClass->school), $lesson);
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

    /**
     * The students marked as present when the session started.
     *
     * @return Collection<int, User>
     */
    public function presentStudents(): Collection
    {
        return User::query()->whereKey($this->present_student_ids ?? [])->get();
    }

    public function isFinished(): bool
    {
        return $this->finished_at !== null;
    }

    /**
     * Whether the session was never ended and has been open for longer than a lesson lasts
     * (`lernplattform.recordings.max_session_hours`): the browser must have crashed. The attendance of that day no
     * longer authorises anything, a new session needs a fresh declaration.
     */
    public function isExpired(): bool
    {
        return ! $this->isFinished() && $this->started_at->lt(now()->subHours((int) config('lernplattform.recordings.max_session_hours')));
    }

    /**
     * "12:34" or "1:02:03"; null while the duration is unknown (the session was cut off).
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
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'present_student_ids' => 'array',
            'attendance_confirmed_at' => 'datetime',
            'purposes' => 'array',
            'audio_sent_to_ai' => 'boolean',
        ];
    }
}
