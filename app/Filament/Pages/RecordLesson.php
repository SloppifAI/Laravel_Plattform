<?php

namespace App\Filament\Pages;

use App\Filament\Resources\Lessons\LessonResource;
use App\Models\Lesson;
use App\Models\LessonRecording;
use App\Models\User;
use App\Support\Pseudonymizer;
use App\Support\RecordingConsentGate;
use App\Support\RecordingConsentResult;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Panel;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;

/**
 * "Tuto-Mikro": the teacher's microphone in a lesson. Only the teacher's own microphone is used; the browser cannot
 * filter other voices, so the page says so. The page itself does two things: the teacher marks who is present and sees
 * whether the consents allow Tuto-Mikro (RecordingConsentGate, names only on this screen), and the teacher takes over
 * what Tuto made of the notes into the lesson notes. The microphone, the notes text and the summary live in the
 * browser and the endpoints of LessonRecordingController (see the view); nothing of the text is stored here.
 *
 * Only the lesson's teacher gets in (LessonRecordingPolicy), and only while they teach the class: not the school
 * administration, not other teachers, not a substitute whose period is over. A Förderaufgabe and a lesson in a
 * sensitive subject (School::mikroBlockedSubjects) have no page: it answers 403 where RecordingConsentGate::lessonRefusal()
 * has a reason, as the endpoints do.
 */
class RecordLesson extends Page
{
    /**
     * Upper bound for the lesson notes (a MySQL `text` column holds 65,535 bytes).
     */
    private const int MAX_NOTES_BYTES = 60000;

    protected string $view = 'filament.pages.record-lesson';

    protected static ?string $title = 'Tuto-Mikro';

    protected static ?string $slug = 'tuto-mikro/{lesson}';

    protected static bool $shouldRegisterNavigation = false;

    #[Locked]
    public Lesson $lesson;

    /**
     * The ids of the students who are present, from the checkboxes. Client-controlled: the start endpoint checks them again.
     *
     * @var list<int|string>
     */
    public array $present = [];

    /**
     * The teacher's declaration "only the people ticked are in the room". It belongs to one list: every change of the
     * list takes it back, and the start endpoint refuses a start without it.
     */
    public bool $attendanceConfirmed = false;

    /**
     * Whether the consents allow starting right now, set by this component only; the page enables "Mikro an" with it.
     */
    #[Locked]
    public bool $canStart = false;

    public static function canAccess(): bool
    {
        return auth()->user()?->isTeacher() ?? false;
    }

    public static function getRelativeRouteName(Panel $panel): string
    {
        return 'tuto-mikro';
    }

    public function mount(Lesson $lesson): void
    {
        Gate::authorize('create', [LessonRecording::class, $lesson]);
        // A Förderaufgabe or a lesson in a subject without Tuto-Mikro: the lesson page hides the button, a typed URL gets nothing either.
        abort_if(RecordingConsentGate::lessonRefusal($lesson) !== null, 403);

        $this->lesson = $lesson;
        $this->selectAll();
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
            'Tuto-Mikro',
        ];
    }

    public function selectAll(): void
    {
        $this->present = $this->students()->modelKeys();
        $this->attendanceConfirmed = false;
        $this->recheck();
    }

    public function selectNone(): void
    {
        $this->present = [];
        $this->attendanceConfirmed = false;
        $this->recheck();
    }

    public function updatedPresent(): void
    {
        $this->attendanceConfirmed = false;
        $this->recheck();
    }

    /**
     * Checks the consents again (also asked for by the page after a refused start, because a consent can change).
     */
    public function recheck(): void
    {
        $this->canStart = $this->consentCheck()->allowed;
    }

    /**
     * Puts the result of the summary into the lesson notes. What is sent is what the browser shows, and the browser
     * is not trusted: the rights are checked again and the names of the class are replaced again.
     */
    public function applyToNotes(string $text): void
    {
        Gate::authorize('create', [LessonRecording::class, $this->lesson]);

        if ($this->appendToNotes($text)) {
            Notification::make()
                ->success()
                ->title(__('In die Stundennotizen übernommen'))
                ->body(__('Namen aus deiner Klasse wurden durch Platzhalter ersetzt, weil die Notizen für die Hausaufgabe der KI verwendet werden.'))
                ->send();
        }
    }

    /**
     * Puts the result into the notes and sends the teacher to the lesson, where the homework is created from the notes.
     */
    public function createHomework(string $text): void
    {
        Gate::authorize('create', [LessonRecording::class, $this->lesson]);

        if ($this->lesson->isPublished()) {
            Notification::make()->warning()->title(__('Diese Stunde ist schon freigegeben'))->body(__('Die Hausaufgabe lässt sich nur für eine Stunde im Entwurf neu erstellen.'))->send();

            return;
        }

        if (! $this->appendToNotes($text)) {
            return;
        }

        Notification::make()
            ->success()
            ->title(__('Das Ergebnis steht in den Stundennotizen'))
            ->body(__('Erstelle jetzt dort die Hausaufgabe aus deinen Notizen.'))
            ->send();

        $this->redirect(LessonResource::getUrl('edit', ['record' => $this->lesson]));
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        // The lesson survives in the Livewire snapshot, so every render re-checks the rights.
        Gate::authorize('create', [LessonRecording::class, $this->lesson]);

        $school = $this->lesson->schoolClass->school;
        $consentForm = $school->currentConsentForm();

        return [
            'students' => $this->students(),
            'check' => $this->consentCheck(),
            'schoolEnabled' => $school->recordingsEnabled(),
            'aiEnabled' => $school->aiEnabled(),
            'providerName' => Str::headline((string) config('lernplattform.ai.provider')),
            'consentFormVersion' => $consentForm?->version,
            'homeworkLocked' => $this->lesson->isPublished(),
            'boardUrl' => MikroBoard::getUrl(['lesson' => $this->lesson]),
            'sessionKeepDays' => (int) config('lernplattform.recordings.session_keep_days'),
            'sessions' => LessonRecording::query()->where('lesson_id', $this->lesson->getKey())->latest('started_at')->limit(8)->get(),
            'micConfig' => [
                'csrf' => csrf_token(),
                'startUrl' => route('recordings.store', $this->lesson),
                // What this page was rendered with; the start endpoint decides again (the setting may have changed since).
                'aiEnabled' => $school->aiEnabled(),
                'maxTextChars' => (int) config('lernplattform.recordings.max_text_chars'),
                // The Beamer window listens on this channel (same browser only); it carries "on" or "off" and nothing else.
                'boardChannel' => MikroBoard::channelName($this->lesson),
                'texts' => $this->browserTexts(),
            ],
        ];
    }

    /**
     * Every sentence the script of the page shows (bar, warnings, errors, announcements) in the teacher's language.
     * The script sits in a @verbatim block, so the texts travel with the component config instead.
     *
     * @return array<string, string>
     */
    private function browserTexts(): array
    {
        return [
            'micOn' => __('Mikro an'),
            'micOff' => __('Mikro aus'),
            'barOn' => __('Mikro an – Tuto schreibt mit'),
            'barManual' => __('Mikro aus – du schreibst die Notizen selbst'),
            'barLastPart' => __('Der letzte Teil wird noch mitgeschrieben'),
            'barMuted' => __('Kein Ton: Mikro stumm oder getrennt'),
            'askingBrowser' => __('Dein Browser fragt jetzt nach dem Mikrofon.'),
            'settingsChanged' => __('Die Einstellungen deiner Schule haben sich geändert. Lade die Seite neu und schalte Tuto-Mikro noch einmal an.'),
            'cannotSplit' => __('Dein Browser kann den Ton nicht in Abschnitte teilen. Bitte probiere eine aktuelle Version von Chrome, Edge, Firefox oder Safari.'),
            'announceManual' => __('Das Mikro bleibt aus. Du schreibst die Notizen selbst.'),
            'announceOn' => __('Mikro an. Tuto schreibt mit.'),
            'mayNotContinue' => __('Tuto-Mikro darf nicht weiterlaufen.'),
            'micIsOff' => __('Das Mikro ist aus.'),
            'loginExpiredMicOff' => __('Deine Anmeldung ist abgelaufen. Das Mikro ist aus. Kopiere deine Notizen, bevor du die Seite neu lädst.'),
            'startNotEnabled' => __('Tuto-Mikro ist für deine Schule gerade nicht freigeschaltet. Die Schuladministration entscheidet darüber.'),
            'loginExpiredReload' => __('Deine Anmeldung ist abgelaufen. Lade die Seite neu und melde dich gegebenenfalls an.'),
            'consentsNotEnough' => __('Die Einwilligungen reichen nicht aus.'),
            'consentsSeeAbove' => __('Was fehlt, steht oben unter „Wer ist heute da?“.'),
            'chooseWhoIsHere' => __('Wähle zuerst aus, wer heute da ist.'),
            'tooManyStarts' => __('Du hast zu oft gestartet. Warte eine Minute und versuche es noch einmal.'),
            'startFailed' => __('Tuto-Mikro konnte nicht starten. Bitte lade die Seite neu und versuche es noch einmal.'),
            'micPromptDeclined' => __('Das Mikrofon wurde nicht freigegeben: Du hast die Anfrage des Browsers abgelehnt oder das Fenster geschlossen. Drücke noch einmal „Mikro an“ und wähle „Zulassen“.'),
            'micBlockedByBrowser' => __('Der Browser hat den Zugriff auf das Mikrofon nicht erlaubt. Wie du ihn erlaubst, steht unten.'),
            'micDeclinedSeeHelp' => __('Das Mikrofon wurde nicht freigegeben: Du hast die Anfrage des Browsers abgelehnt oder das Fenster geschlossen. Fragt der Browser nicht mehr nach, hilft die Anleitung unten.'),
            'micSecurity' => __('Der Browser erlaubt dieser Seite hier keinen Zugriff auf das Mikrofon. Das liegt meist an einer ungesicherten Verbindung (http statt https).'),
            'micNotFound' => __('Es wurde kein Mikrofon gefunden. Schließe ein Headset oder ein Ansteckmikrofon an und versuche es noch einmal.'),
            'micBusy' => __('Das Mikrofon wird gerade von einem anderen Programm benutzt. Schließe es dort und versuche es noch einmal.'),
            'micFailed' => __('Das Mikrofon konnte nicht gestartet werden. Bitte versuche es noch einmal.'),
            'interrupted' => __('Tuto-Mikro wurde vom Browser unterbrochen.'),
            'interruptedMicOff' => __('Tuto-Mikro wurde vom Browser unterbrochen. Das Mikro ist aus.'),
            'connectionTooSlow' => __('Die Verbindung ist zu langsam: Ein Teil der Stunde fehlt in den Notizen.'),
            'segmentLost' => __('Ein Teil der Stunde konnte nicht mitgeschrieben werden und fehlt in den Notizen. Das Mikro ist weiter an.'),
            'announceStopping' => __('Mikro aus. Der letzte Teil wird noch mitgeschrieben.'),
            'announceStopped' => __('Mikro aus. Die Notizen aus der Stunde stehen unten. Prüfe sie und erstelle dann die Zusammenfassung.'),
            'micDisconnected' => __('Das Mikrofon wurde getrennt oder gesperrt. Das Mikro ist aus.'),
            'speechUnavailable' => __('Tuto kann hier nicht mitschreiben, weil die Spracherkennung auf diesem Gerät nicht läuft. Das Mikro ist aus, du kannst die Notizen selbst tippen.'),
            'summaryLoginExpired' => __('Deine Anmeldung ist abgelaufen. Kopiere deine Notizen und lade die Seite neu.'),
            'summaryFailed' => __('Die Zusammenfassung konnte nicht erstellt werden. Deine Notizen sind noch da.'),
            'summaryNoAnswer' => __('Die Zusammenfassung konnte nicht erstellt werden, die Verbindung hat nicht geantwortet. Deine Notizen sind noch da.'),
            'summaryReady' => __('Die Zusammenfassung ist fertig. Prüfe sie, bevor du etwas übernimmst.'),
            'discardConfirm' => __('Die Notizen aus der Stunde werden gelöscht. Was du schon übernommen hast, bleibt in den Stundennotizen. Fortfahren?'),
            'notesDeleted' => __('Die Notizen sind gelöscht.'),
        ];
    }

    /**
     * @return Collection<int, User>
     */
    private function students(): Collection
    {
        return $this->lesson->schoolClass->students()->orderBy('name')->get();
    }

    private function consentCheck(): RecordingConsentResult
    {
        $ids = array_slice(array_values(array_unique(array_map(intval(...), $this->present))), 0, 500);

        return LessonRecording::consentCheck($this->lesson, auth()->user(), $this->lesson->schoolClass->students()->whereKey($ids)->get());
    }

    /**
     * Appends the text to the lesson's notes. The notes feed the homework draft of the AI, so names of the class are replaced first.
     */
    private function appendToNotes(string $text): bool
    {
        $text = trim($text);

        if ($text === '') {
            Notification::make()->warning()->title(__('Noch nichts zum Übernehmen'))->body(__('Erstelle zuerst eine Zusammenfassung.'))->send();

            return false;
        }

        $lesson = $this->lesson->refresh();
        // What lands in the notes stays German like the notes themselves: the AI reads them for the homework draft.
        $heading = 'Tuto-Mikro vom '.now()->format('d.m.Y, H:i').' Uhr:';
        $notes = trim(trim($lesson->notes)."\n\n".$heading."\n".Pseudonymizer::forLesson($lesson)->apply($text));

        if (strlen($notes) > self::MAX_NOTES_BYTES) {
            Notification::make()->danger()->title(__('Die Stundennotizen wären zu lang'))->body(__('Kürze die Notizen in der Stunde, dann klappt die Übernahme.'))->send();

            return false;
        }

        $lesson->update(['notes' => $notes]);

        return true;
    }
}
