<?php

namespace App\Filament\Pages;

use App\Ai\RecordingAnalyst;
use App\Ai\Transcriber;
use App\Ai\TranscriberException;
use App\Filament\Resources\Lessons\LessonResource;
use App\Models\Lesson;
use App\Models\LessonRecording;
use App\Support\Pseudonymizer;
use App\TranscriptStatus;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Panel;
use Filament\Support\Enums\Size;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Throwable;

/**
 * "Stunde aufnehmen": the teacher's audio-only recording mode. The recorder itself runs in the browser (see the view);
 * this page hosts it and manages the saved recordings: listening, transcript, evaluation, notes and deleting.
 *
 * Only the lesson's teacher gets in (LessonRecordingPolicy): not the school administration, not other teachers.
 * The AI never receives audio, and the text only pseudonymised (Pseudonymizer).
 */
class RecordLesson extends Page
{
    /**
     * Upper bound for the lesson notes (a MySQL `text` column holds 65,535 bytes).
     */
    private const int MAX_NOTES_BYTES = 60000;

    private const int MAX_TRANSCRIPT_LENGTH = 200000;

    protected string $view = 'filament.pages.record-lesson';

    protected static ?string $title = 'Stunde aufnehmen';

    protected static ?string $slug = 'aufnahme/{lesson}';

    protected static bool $shouldRegisterNavigation = false;

    #[Locked]
    public Lesson $lesson;

    /**
     * The transcript text boxes, keyed by recording id. Client-controlled: every action re-reads the recording itself.
     *
     * @var array<int, string>
     */
    public array $transcripts = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->isTeacher() ?? false;
    }

    public static function getRelativeRouteName(Panel $panel): string
    {
        return 'aufnahme';
    }

    public function mount(Lesson $lesson): void
    {
        Gate::authorize('create', [LessonRecording::class, $lesson]);

        $this->lesson = $lesson;
        $this->reloadRecordings();
    }

    public function getSubheading(): ?string
    {
        return collect([$this->lesson->subject, $this->lesson->schoolClass->name, $this->lesson->topic])->filter()->implode(' · ');
    }

    /**
     * @return array<string, string>
     */
    public function getBreadcrumbs(): array
    {
        return [
            LessonResource::getUrl() => LessonResource::getBreadcrumb(),
            LessonResource::getUrl('edit', ['record' => $this->lesson]) => $this->lesson->topic,
            'Aufnahme',
        ];
    }

    /**
     * Called by the recorder when a recording was saved, so it shows up in the list. Text a teacher is editing stays.
     * A recording that is still running has no text box yet, so it is read once it is finished.
     */
    public function reloadRecordings(): void
    {
        foreach ($this->recordings()->reject->isLive() as $recording) {
            $this->transcripts[$recording->getKey()] ??= (string) $recording->transcript;
        }
    }

    public function saveTranscript(int $recordingId): void
    {
        $recording = $this->recording($recordingId);

        if ($this->storeTranscript($recording)) {
            Notification::make()->success()->title('Transkript gespeichert')->send();
        }
    }

    /**
     * Server-side speech-to-text. Only possible once the transcription is enabled; until then the button is disabled.
     */
    public function createTranscript(int $recordingId): void
    {
        $recording = $this->recording($recordingId);

        if (! config('services.transcription.enabled')) {
            Notification::make()->warning()->title('Transkription noch nicht freigeschaltet')->body('Du kannst den Text unten selbst einfügen oder korrigieren.')->send();

            return;
        }

        $this->store($recording, ['transcript_status' => TranscriptStatus::Processing]);

        try {
            $text = app(Transcriber::class)->transcribe($recording);
        } catch (Throwable $exception) {
            report($exception);
            $this->store($recording, ['transcript_status' => TranscriptStatus::Failed]);

            Notification::make()
                ->danger()
                ->title('Transkription fehlgeschlagen')
                ->body($exception instanceof TranscriberException ? $exception->getMessage() : 'Bitte versuche es gleich noch einmal. Du kannst den Text auch selbst einfügen.')
                ->persistent()
                ->send();

            return;
        }

        $this->store($recording, [
            'transcript' => $text,
            'transcript_status' => TranscriptStatus::Done,
            'transcript_source' => LessonRecording::SOURCE_SERVER,
        ]);
        $this->transcripts[$recording->getKey()] = $text;

        Notification::make()->success()->title('Transkript erstellt')->body('Bitte lies es einmal durch, bevor du es auswerten lässt.')->send();
    }

    /**
     * Evaluates the transcript as it stands in the text box (saved first, so what is evaluated is what is stored).
     */
    public function analyse(int $recordingId): void
    {
        $recording = $this->recording($recordingId);

        if (! $this->storeTranscript($recording)) {
            return;
        }

        $transcript = (string) $recording->transcript;

        if ($transcript === '') {
            Notification::make()->warning()->title('Das Transkript ist noch leer')->body('Füge zuerst Text ein oder nimm die Stunde mit Live-Transkript auf.')->send();

            return;
        }

        try {
            $analysis = app(RecordingAnalyst::class)->analyse($recording, $transcript);
        } catch (Throwable $exception) {
            report($exception);

            Notification::make()
                ->danger()
                ->title('Auswertung fehlgeschlagen')
                ->body('Dein Transkript ist gespeichert. Bitte versuche es gleich noch einmal.')
                ->persistent()
                ->send();

            return;
        }

        $this->store($recording, ['analysis' => $analysis, 'analysed_at' => now()]);

        Notification::make()
            ->success()
            ->title('Auswertung fertig')
            ->body(config('services.anthropic.enabled')
                ? 'Prüfe die Ergebnisse, bevor du sie übernimmst.'
                : 'Demo-Modus – KI-Anbindung vorbereitet, aber nicht aktiv. Die Auswertung entstand lokal aus deinem Text, es wurde nichts gesendet.')
            ->send();
    }

    public function applyToNotes(int $recordingId): void
    {
        $recording = $this->recording($recordingId);

        if ($this->appendToNotes($recording)) {
            Notification::make()
                ->success()
                ->title('In die Stundennotizen übernommen')
                ->body('Namen aus deiner Klasse wurden durch Platzhalter ersetzt, weil die Notizen für die KI-Hausaufgabe verwendet werden.')
                ->send();
        }
    }

    /**
     * Puts the result into the notes and sends the teacher to the lesson, where "Hausaufgabe mit KI erstellen" takes over.
     */
    public function createHomework(int $recordingId): void
    {
        $recording = $this->recording($recordingId);

        if ($this->lesson->isPublished()) {
            Notification::make()->warning()->title('Diese Stunde ist schon verteilt')->body('Die Hausaufgabe lässt sich nur für eine Stunde im Entwurf neu erstellen.')->send();

            return;
        }

        if (! $this->appendToNotes($recording)) {
            return;
        }

        Notification::make()
            ->success()
            ->title('Ergebnis steht in den Stundennotizen')
            ->body('Wähle jetzt oben „Hausaufgabe mit KI erstellen“.')
            ->send();

        $this->redirect(LessonResource::getUrl('edit', ['record' => $this->lesson]));
    }

    /**
     * "Löschen" for one recording (argument `recording`): the audio file, the transcript and the evaluation go with it.
     */
    public function deleteRecordingAction(): Action
    {
        return Action::make('deleteRecording')
            ->label('Löschen')
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->size(Size::Small)
            ->requiresConfirmation()
            ->modalHeading('Aufnahme löschen?')
            ->modalDescription('Die Audiodatei, das Transkript und die Auswertung werden endgültig gelöscht. Das lässt sich nicht rückgängig machen.')
            ->modalSubmitActionLabel('Endgültig löschen')
            ->action(function (array $arguments): void {
                $recording = $this->recording((int) ($arguments['recording'] ?? 0), 'delete');

                if ($recording->isLive()) {
                    Notification::make()->warning()->title('Diese Aufnahme läuft gerade')->body('Beende sie zuerst, danach kannst du sie löschen.')->send();

                    return;
                }

                $recording->delete();
                unset($this->transcripts[$recording->getKey()]);

                Notification::make()->success()->title('Aufnahme gelöscht')->send();
            });
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        // The lesson survives in the Livewire snapshot, so every render re-checks the rights.
        Gate::authorize('create', [LessonRecording::class, $this->lesson]);

        $keepDays = (int) config('lernplattform.recordings.keep_days', 14);
        $canRecord = Gate::allows('record', [LessonRecording::class, $this->lesson]);

        return [
            'recordings' => $this->recordings(),
            'keepDays' => $keepDays,
            'canRecord' => $canRecord,
            'transcriptionEnabled' => (bool) config('services.transcription.enabled'),
            'aiEnabled' => (bool) config('services.anthropic.enabled'),
            'recorderConfig' => [
                'canRecord' => $canRecord,
                'csrf' => csrf_token(),
                'startUrl' => route('recordings.store', $this->lesson),
                'chunkSeconds' => (int) config('lernplattform.recordings.chunk_seconds', 20),
                'bitsPerSecond' => 32000,
            ],
        ];
    }

    /**
     * @return Collection<int, LessonRecording>
     */
    private function recordings(): Collection
    {
        return LessonRecording::query()->where('lesson_id', $this->lesson->getKey())->latest('started_at')->get();
    }

    /**
     * One recording of this lesson, for an ability the teacher holds. The id comes from the browser: a recording of
     * another lesson or teacher is "not found", never touched.
     */
    private function recording(int $id, string $ability = 'update'): LessonRecording
    {
        $recording = LessonRecording::query()->where('lesson_id', $this->lesson->getKey())->findOrFail($id);

        Gate::authorize($ability, $recording);

        return $recording;
    }

    /**
     * Page edits leave `updated_at` alone: it is the recorder's sign of life (LessonRecording::isLive()), and editing
     * the transcript of an interrupted recording must not make it look as if it were running again.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function store(LessonRecording $recording, array $attributes): void
    {
        LessonRecording::withoutTimestamps(fn (): bool => $recording->update($attributes));
    }

    /**
     * Saves the text box as the recording's transcript. Changed text counts as written by hand (source "manual").
     */
    private function storeTranscript(LessonRecording $recording): bool
    {
        $text = trim($this->transcripts[$recording->getKey()] ?? (string) $recording->transcript);

        if (mb_strlen($text) > self::MAX_TRANSCRIPT_LENGTH) {
            Notification::make()->danger()->title('Das Transkript ist zu lang')->body('Es dürfen höchstens '.number_format(self::MAX_TRANSCRIPT_LENGTH, 0, ',', '.').' Zeichen sein.')->send();

            return false;
        }

        if ($text !== (string) $recording->transcript) {
            $this->store($recording, $text === '' ? [
                'transcript' => null,
                'transcript_status' => TranscriptStatus::None,
                'transcript_source' => null,
            ] : [
                'transcript' => $text,
                'transcript_status' => TranscriptStatus::Done,
                'transcript_source' => LessonRecording::SOURCE_MANUAL,
            ]);
        }

        $this->transcripts[$recording->getKey()] = $text;

        return true;
    }

    /**
     * Appends the evaluation's notes (or, without one, the transcript) to the lesson's notes. The notes feed the
     * homework draft of the AI, so names of the class are replaced first.
     */
    private function appendToNotes(LessonRecording $recording): bool
    {
        if (! $this->storeTranscript($recording)) {
            return false;
        }

        $text = trim((string) (($recording->analysis['suggested_notes'] ?? '') ?: $recording->transcript));

        if ($text === '') {
            Notification::make()->warning()->title('Noch nichts zum Übernehmen')->body('Füge zuerst ein Transkript ein oder lass es auswerten.')->send();

            return false;
        }

        $lesson = $this->lesson->refresh();
        $heading = "Aufnahme vom {$recording->started_at->format('d.m.Y, H:i')} Uhr:";
        $notes = trim($lesson->notes)."\n\n".$heading."\n".Pseudonymizer::forLesson($lesson)->apply($text);
        $notes = trim($notes);

        if (strlen($notes) > self::MAX_NOTES_BYTES) {
            Notification::make()
                ->danger()
                ->title('Die Stundennotizen wären zu lang')
                ->body(filled($recording->analysis['suggested_notes'] ?? '')
                    ? 'Kürze die Notizen in der Stunde, dann klappt die Übernahme.'
                    : 'Das Transkript ist zu lang für die Notizen. Lass es zuerst auswerten, dann wird die Zusammenfassung übernommen.')
                ->send();

            return false;
        }

        $lesson->update(['notes' => $notes]);

        return true;
    }
}
