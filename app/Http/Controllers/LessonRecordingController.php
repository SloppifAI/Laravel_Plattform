<?php

namespace App\Http\Controllers;

use App\Ai\RecordingAnalyst;
use App\Ai\Transcriber;
use App\Models\Lesson;
use App\Models\LessonRecording;
use App\Support\Pseudonymizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Throwable;

/**
 * The endpoints behind the Tuto-Mikro page. Everything here is reserved for the lesson's teacher
 * (LessonRecordingPolicy).
 *
 * The browser starts a session with the students marked as present (the consent of each of them is checked here, not
 * only on the page), posts the sound in segments of about 25 seconds, ends the session and asks for the summary of the
 * notes. Nothing is stored: a segment is recognised from memory within the request (it is never written to a disk),
 * the recognised text goes back to the browser, and the summary is computed from the text the browser sends and handed
 * back. Where no segments are sent (recognition on the device, typing), the browser asks `check` at the same rhythm,
 * so a withdrawn consent or a switched-off Tuto-Mikro stops every kind of session.
 */
class LessonRecordingController extends Controller
{
    /**
     * Starts a session: only for the lesson's teacher, in a school whose administration allows recordings, and only when
     * every present student has a valid consent for the purposes about to be processed. The snapshot (present
     * students, the teacher's confirmation of that list, consent form version, purposes) is stored with the session.
     */
    public function store(Request $request, Lesson $lesson): JsonResponse
    {
        Gate::authorize('record', [LessonRecording::class, $lesson]);

        $data = $request->validate([
            'present' => ['required', 'array', 'min:1', 'max:200'],
            'present.*' => ['integer', 'distinct'],
            'attendance_confirmed' => ['accepted'],
        ], [
            'present.required' => __('Wähle zuerst aus, wer heute da ist.'),
            'present.min' => __('Wähle zuerst aus, wer heute da ist.'),
            'attendance_confirmed.accepted' => __('Bestätige, dass nur die angehakten Schüler:innen im Raum sind.'),
        ]);

        $students = $lesson->schoolClass->students()->whereKey($data['present'])->get();

        abort_if($students->count() !== count($data['present']), 422, __('Mindestens eine ausgewählte Person gehört nicht zu dieser Klasse.'));

        $check = LessonRecording::consentCheck($lesson, $request->user(), $students);

        if (! $check->allowed) {
            return response()->json(['message' => $check->reasons[0] ?? __('Tuto-Mikro darf jetzt nicht starten.'), 'reasons' => $check->reasons], 409);
        }

        $school = $lesson->schoolClass->school;

        $recording = LessonRecording::query()->create([
            'lesson_id' => $lesson->getKey(),
            'teacher_id' => $request->user()->getKey(),
            'started_at' => now(),
            'present_student_ids' => $students->modelKeys(),
            'attendance_confirmed_at' => now(),
            'consent_form_version' => $school->currentConsentForm()?->version,
            'purposes' => LessonRecording::purposesFor($school),
        ]);

        return response()->json([
            'id' => $recording->getKey(),
            // Whether the sound is recognised on the server; without it the browser sends nothing and recognises on the device, if it can.
            'transcribes' => $school->aiEnabled(),
            'segment_seconds' => (int) config('lernplattform.recordings.segment_seconds'),
            'segment_url' => route('recordings.segment', $recording),
            'check_url' => route('recordings.check', $recording),
            'end_url' => route('recordings.finish', $recording),
            'analyse_url' => route('recordings.analyse', $recording),
        ], 201);
    }

    /**
     * Whether the teacher's Tuto-Mikro session of this lesson is open: the one fact the Beamer display (MikroBoard) asks
     * for when it cannot hear the Tuto-Mikro tab (another device). Nothing about the class or the notes is in the answer.
     */
    public function status(Request $request, Lesson $lesson): JsonResponse
    {
        Gate::authorize('create', [LessonRecording::class, $lesson]);

        $recording = LessonRecording::query()
            ->where('lesson_id', $lesson->getKey())
            ->where('teacher_id', $request->user()->getKey())
            ->whereNull('finished_at')
            ->latest('started_at')
            ->first();

        return response()->json(['on' => $recording !== null && ! $recording->isExpired()]);
    }

    /**
     * One segment of sound (raw body): recognised from memory, and the text goes back to the browser. The sound is never
     * written to a disk, so a request that dies leaves nothing behind. The session must still be open, the school's AI
     * must still be on (the browser only sends segments in that mode, so anything else is refused and the browser turns
     * the microphone off), and the consent is checked again for every segment, because it can be revoked during the lesson.
     */
    public function segment(Request $request, LessonRecording $recording): JsonResponse
    {
        Gate::authorize('update', $recording);

        if ($refusal = $this->openRefusal($recording)) {
            return $refusal;
        }

        $lesson = $recording->lesson;

        if (! $lesson->schoolClass->school->aiEnabled()) {
            return response()->json(['message' => __('Die KI ist für deine Schule ausgeschaltet, deshalb wird nichts mehr mitgeschrieben.')], 409);
        }

        $mimeType = strtolower(trim(Str::before((string) $request->header('Content-Type'), ';')));
        $maxBytes = (int) config('lernplattform.recordings.max_segment_kb') * 1024;

        abort_unless(array_key_exists($mimeType, LessonRecording::MIME_TYPES), 415, __('Dieses Audioformat wird nicht unterstützt.'));
        abort_if((int) $request->header('Content-Length') > $maxBytes, 413, __('Dieser Abschnitt ist zu groß.'));

        $audio = $request->getContent();

        abort_if($audio === '', 422, __('Dieser Abschnitt ist leer.'));
        abort_if(strlen($audio) > $maxBytes, 413, __('Dieser Abschnitt ist zu groß.'));

        if ($refusal = $this->consentRefusal($request, $recording, __('Tuto-Mikro darf jetzt nicht weiterlaufen.'))) {
            return $refusal;
        }

        if (! $recording->audio_sent_to_ai) {
            $recording->update(['audio_sent_to_ai' => true]);
        }

        try {
            $text = app(Transcriber::class)->transcribe($lesson, $audio, $mimeType);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json(['message' => __('Ein Abschnitt konnte nicht mitgeschrieben werden.')], 502);
        }

        return response()->json(['text' => $text === null ? null : trim($text)]);
    }

    /**
     * The heartbeat of a session that sends no segments (recognition on the device, or typing): the same checks as in
     * front of a segment, without any sound. 409 means the browser must turn the microphone off.
     */
    public function check(Request $request, LessonRecording $recording): JsonResponse
    {
        Gate::authorize('update', $recording);

        return $this->openRefusal($recording)
            ?? $this->consentRefusal($request, $recording, __('Tuto-Mikro darf jetzt nicht weiterlaufen.'))
            ?? response()->json(['allowed' => true]);
    }

    /**
     * Ends the session and stores its duration. Repeating the call is harmless.
     */
    public function finish(Request $request, LessonRecording $recording): JsonResponse
    {
        Gate::authorize('update', $recording);

        $data = $request->validate(['duration_seconds' => ['required', 'integer', 'min:0', 'max:86400']]);

        if (! $recording->isFinished()) {
            $recording->update(['finished_at' => now(), 'duration_seconds' => $data['duration_seconds']]);
        }

        return response()->json(['finished' => true, 'duration_seconds' => $recording->duration_seconds]);
    }

    /**
     * "Zusammenfassung erstellen": the notes as the teacher reviewed them. The consent of the present students is
     * checked again first, in every school (a withdrawn consent or a switched-off Tuto-Mikro stops the summary, and in
     * a school with AI the check includes the consent to the AI analysis). Then the names of the class are replaced
     * (Pseudonymizer) and the RecordingAnalyst works on the text (the AI only for a school that switched it on).
     * Nothing is stored; the teacher takes over what she or he wants on the page.
     */
    public function analyse(Request $request, LessonRecording $recording): JsonResponse
    {
        Gate::authorize('update', $recording);

        $data = $request->validate(
            ['text' => ['required', 'string', 'max:'.(int) config('lernplattform.recordings.max_text_chars')]],
            ['text.required' => __('Es gibt noch keine Notizen, aus denen Tuto etwas machen kann.'), 'text.max' => __('Die Notizen sind zu lang für eine Zusammenfassung.')],
        );

        if ($recording->isExpired()) {
            return $this->expiredResponse();
        }

        if ($refusal = $this->consentRefusal($request, $recording, __('Die Zusammenfassung darf jetzt nicht erstellt werden.'))) {
            return $refusal;
        }

        $lesson = $recording->lesson;

        try {
            $analysis = app(RecordingAnalyst::class)->analyse($lesson, Pseudonymizer::forLesson($lesson)->apply(trim($data['text'])));
        } catch (Throwable $exception) {
            report($exception);

            return response()->json(['message' => __('Die Zusammenfassung konnte nicht erstellt werden. Deine Notizen sind noch da. Bitte versuche es gleich noch einmal.')], 502);
        }

        return response()->json(['analysis' => $analysis]);
    }

    /**
     * Why a session takes nothing more: it is over, or it was never ended and is too old.
     */
    private function openRefusal(LessonRecording $recording): ?JsonResponse
    {
        if ($recording->isFinished()) {
            return response()->json(['message' => __('Das Mikro ist schon aus.')], 409);
        }

        return $recording->isExpired() ? $this->expiredResponse() : null;
    }

    private function expiredResponse(): JsonResponse
    {
        return response()->json(['message' => __('Diese Sitzung läuft schon zu lange. Starte Tuto-Mikro neu und wähle die Anwesenden noch einmal aus.')], 409);
    }

    /**
     * The consent check against the snapshot of the session, for the purposes the school's settings call for right now.
     */
    private function consentRefusal(Request $request, LessonRecording $recording, string $fallbackMessage): ?JsonResponse
    {
        $check = LessonRecording::consentCheck($recording->lesson, $request->user(), $recording->presentStudents());

        return $check->allowed ? null : response()->json(['message' => $check->reasons[0] ?? $fallbackMessage, 'reasons' => $check->reasons], 409);
    }
}
