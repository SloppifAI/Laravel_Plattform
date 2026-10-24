<?php

namespace App\Http\Controllers;

use App\Models\Lesson;
use App\Models\LessonRecording;
use App\TranscriptStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Upload and playback of lesson recordings. Everything here is reserved for the recording's own teacher
 * (LessonRecordingPolicy); the file is only ever delivered through these authorised routes.
 *
 * The browser creates the recording once the teacher confirmed the consent and the microphone is available, then
 * posts the raw MediaRecorder chunks one after another and finishes with the duration.
 */
class LessonRecordingController extends Controller
{
    /**
     * Creates the recording row; the consent confirmation (who, when) is stored with it. Only where the school's
     * administration has switched recordings on.
     */
    public function store(Request $request, Lesson $lesson): JsonResponse
    {
        Gate::authorize('record', [LessonRecording::class, $lesson]);

        $unfinished = LessonRecording::query()
            ->where('teacher_id', $request->user()->getKey())
            ->whereNull('finished_at')
            ->where('started_at', '>', now()->subHours(12))
            ->count();

        abort_if($unfinished >= (int) config('lernplattform.recordings.max_unfinished'), 429, 'Es gibt schon mehrere nicht beendete Aufnahmen. Lade die Seite neu und lösche, was du nicht brauchst.');

        $data = $request->validate([
            'consent' => ['required', 'accepted'],
            'mime_type' => ['required', 'string', Rule::in(array_keys(LessonRecording::MIME_TYPES))],
        ], [
            'consent.accepted' => 'Bitte bestätige zuerst, dass die Klasse informiert ist und Aufnahmen an deiner Schule erlaubt sind.',
        ]);

        $recording = LessonRecording::query()->create([
            'lesson_id' => $lesson->getKey(),
            'teacher_id' => $request->user()->getKey(),
            'path' => LessonRecording::newPath($lesson, $data['mime_type']),
            'mime_type' => $data['mime_type'],
            'consent_confirmed_at' => now(),
            'started_at' => now(),
        ]);

        return response()->json([
            'id' => $recording->getKey(),
            'append_url' => route('recordings.append', $recording),
            'finish_url' => route('recordings.finish', $recording),
            'transcript_url' => route('recordings.transcript', $recording),
        ], 201);
    }

    /**
     * Appends the next chunk (raw body, `?index=` counting from 0). A chunk that was already stored is acknowledged
     * again without being appended twice, so the browser can safely retry after a lost response.
     */
    public function append(Request $request, LessonRecording $recording): JsonResponse
    {
        Gate::authorize('update', $recording);

        $mimeType = strtolower(trim(Str::before((string) $request->header('Content-Type'), ';')));
        $maxBytes = (int) config('lernplattform.recordings.max_chunk_kb') * 1024;
        $index = $request->query('index');

        abort_unless($mimeType === $recording->mime_type, 415, 'Dieses Audioformat passt nicht zur Aufnahme.');
        abort_unless(is_string($index) && ctype_digit($index), 422, 'Die Nummer des Teils fehlt.');
        abort_if((int) $request->header('Content-Length') > $maxBytes, 413, 'Dieser Teil der Aufnahme ist zu groß.');

        $chunk = $request->getContent();

        abort_if($chunk === '', 422, 'Dieser Teil der Aufnahme ist leer.');
        abort_if(strlen($chunk) > $maxBytes, 413, 'Dieser Teil der Aufnahme ist zu groß.');

        return DB::transaction(function () use ($recording, $chunk, $index): JsonResponse {
            $current = LessonRecording::query()->lockForUpdate()->findOrFail($recording->getKey());

            abort_if($current->isFinished(), 409, 'Diese Aufnahme ist bereits beendet.');

            if ((int) $index < $current->chunks_received) {
                return response()->json(['received' => $current->chunks_received, 'duplicate' => true]);
            }

            abort_if((int) $index > $current->chunks_received, 409, 'Ein früherer Teil der Aufnahme fehlt noch.');

            $this->appendToFile($current, $chunk);
            $current->increment('chunks_received');

            return response()->json(['received' => $current->chunks_received]);
        });
    }

    /**
     * Ends the recording and stores its duration. Repeating the call is harmless; a recording without any sound is dropped.
     */
    public function finish(Request $request, LessonRecording $recording): JsonResponse
    {
        Gate::authorize('update', $recording);

        $data = $request->validate(['duration_seconds' => ['required', 'integer', 'min:0', 'max:86400']]);

        if ($recording->chunks_received === 0) {
            $recording->delete();

            return response()->json(['discarded' => true]);
        }

        if (! $recording->isFinished()) {
            $recording->update(['finished_at' => now(), 'duration_seconds' => $data['duration_seconds']]);
        }

        return response()->json(['finished' => true, 'duration_seconds' => $recording->duration_seconds]);
    }

    /**
     * The live transcript the teacher's own browser recognised on the device. Only while recording; afterwards the
     * transcript is edited on the page. Every call, also one without text, is the recorder's heartbeat: it shows the
     * page that this recording is still running, even while paused.
     */
    public function transcript(Request $request, LessonRecording $recording): JsonResponse
    {
        Gate::authorize('update', $recording);

        abort_if($recording->isFinished(), 409, 'Diese Aufnahme ist bereits beendet.');

        $recording->touch();

        $text = trim((string) $request->validate(['transcript' => ['present', 'nullable', 'string', 'max:200000']])['transcript']);

        if ($text !== '') {
            $recording->update([
                'transcript' => $text,
                'transcript_status' => TranscriptStatus::Live,
                'transcript_source' => LessonRecording::SOURCE_ON_DEVICE,
            ]);
        }

        return response()->json(['saved' => $text !== '']);
    }

    public function stream(LessonRecording $recording): BinaryFileResponse
    {
        Gate::authorize('view', $recording);

        return $this->file($recording, false);
    }

    public function download(LessonRecording $recording): BinaryFileResponse
    {
        Gate::authorize('view', $recording);

        return $this->file($recording, true);
    }

    /**
     * Served by the file response itself so the audio element can seek (range requests); never cached, never sniffed.
     */
    private function file(LessonRecording $recording, bool $asDownload): BinaryFileResponse
    {
        abort_unless($recording->hasAudio(), 404);

        $path = LessonRecording::disk()->path($recording->path);
        $headers = ['Content-Type' => $recording->mime_type, 'X-Content-Type-Options' => 'nosniff'];

        if ($asDownload) {
            $name = Str::slug("Aufnahme {$recording->lesson->subject} {$recording->started_at->format('Y-m-d H-i')}").'.'.LessonRecording::MIME_TYPES[$recording->mime_type];
            $response = response()->download($path, $name, $headers);
        } else {
            $response = response()->file($path, $headers);
        }

        // The file response marks itself public; a classroom recording must never sit in a shared cache.
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    /**
     * Appends bytes without any separator; a failed or short write is rolled back so a retry cannot corrupt the file.
     */
    private function appendToFile(LessonRecording $recording, string $chunk): void
    {
        $disk = LessonRecording::disk();
        $disk->makeDirectory(dirname($recording->path));
        $path = $disk->path($recording->path);
        $size = is_file($path) ? (int) filesize($path) : 0;

        abort_if($size + strlen($chunk) > (int) config('lernplattform.recordings.max_total_mb') * 1024 * 1024, 413, 'Die Aufnahme ist zu lang für den Speicher.');

        if (@file_put_contents($path, $chunk, FILE_APPEND | LOCK_EX) !== strlen($chunk)) {
            $handle = @fopen($path, 'r+b');

            if ($handle !== false) {
                ftruncate($handle, $size);
                fclose($handle);
            }

            abort(507, 'Der Speicherplatz reicht nicht aus.');
        }
    }
}
