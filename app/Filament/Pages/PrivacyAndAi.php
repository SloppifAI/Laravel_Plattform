<?php

namespace App\Filament\Pages;

use App\Role;
use App\UnderstandingLevel;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * Plain-language transparency page for every role: what is stored, who sees it (within the own school only) and what the AI gets.
 * The "who sees what" cards describe app/Policies; tests/Feature/Privacy/PrivacyAndAiTest keeps the AI status in sync with
 * the school's switch and checks that aiFunctions() names every method of the AI interfaces.
 */
class PrivacyAndAi extends Page
{
    protected static ?string $slug = 'datenschutz';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static ?int $navigationSort = 100;

    protected string $view = 'filament.pages.privacy-and-ai';

    public static function getNavigationLabel(): string
    {
        return __('Datenschutz & KI');
    }

    public function getTitle(): string
    {
        return __('Datenschutz & KI');
    }

    public function getSubheading(): string
    {
        return __('Was Tuto speichert, wer es sieht und was die KI damit macht.');
    }

    /**
     * Whether the AI runs for the signed-in user: the switch of their school (School::aiEnabled(): platform switch,
     * the school's opt-in and its checklist), for the operator the platform switch.
     */
    public static function isAiEnabled(): bool
    {
        $school = auth()->user()?->school;

        return $school === null ? (bool) config('lernplattform.ai.enabled') : $school->aiEnabled();
    }

    /**
     * Every call to the AI provider, keyed by the interface method that makes it (app/Ai), with what goes out, what
     * stays and what comes back. All texts pass the Pseudonymizer first; the provider is only reached for a school
     * that switched the AI on.
     *
     * @return array<string, array{title: string, sent: string, notSent: string, result: string}>
     */
    public static function aiFunctions(): array
    {
        return [
            'LessonAssistant::draftHomework' => [
                'title' => __('Hausaufgabe entwerfen'),
                'sent' => __('Fach, Thema, Datum, Zusammenfassung und Notizen der Lehrkraft (auch übernommene Notizen aus der Stunde), die Fragen, die die Lehrkraft selbst geschrieben oder bearbeitet hat (pseudonymisiert, damit die KI sie nicht wiederholt), die Kompetenzen des Fachs und angehängte Arbeitsblätter (Fotos, PDF).'),
                'notSent' => __('Texte der Schüler:innen.'),
                'result' => __('Eine Zusammenfassung und fünf Fragen als Entwurf. Die Lehrkraft prüft ihn, bevor sie ihn freigibt.'),
            ],
            'LessonAssistant::draftPracticeTask' => [
                'title' => __('Förderaufgabe entwerfen'),
                'sent' => __('Fach, Kompetenz, anonyme Hinweise auf Lücken, dazu Thema, Datum und Notizen der Stunden, in denen sich die Lücke gezeigt hat.'),
                'notSent' => __('Arbeitsblätter, Antworten der Schüler:innen und wer die Lücke hatte.'),
                'result' => __('Drei bis vier Übungsfragen. Mit „Direkt freigeben“ gehen sie ohne getrennte Prüfung an die ausgewählten Schüler:innen.'),
            ],
            'LessonAssistant::evaluateSubmission' => [
                'title' => __('Abgabe einschätzen'),
                'sent' => __('Die Stunde wie beim Entwurf mit Arbeitsblättern, die Fragen mit Erwartungshorizont und der gewählten Stufe, die Zusammenfassung und die Antworten der Schüler:in.'),
                'notSent' => __('Andere Abgaben und alles, was zum Konto gehört.'),
                'result' => __('Verständniswert von 0 bis 100, Rückmeldung, bis zu drei Hinweise auf Lücken und Punkte je Antwort. Die Schüler:in sieht das Ergebnis gleich nach der Abgabe.'),
            ],
            'LessonAssistant::differentiateQuestion' => [
                'title' => __('Stufen einer Frage'),
                'sent' => __('Fach, Thema, Zusammenfassung der Lehrkraft, Art und Text der Frage.'),
                'notSent' => __('Erwartungshorizont, Notizen, Arbeitsblätter und Texte der Schüler:innen.'),
                'result' => __('Eine leichtere Fassung (Einstieg) und eine anspruchsvollere (Profi). Die Lehrkraft prüft sie, bevor sie die Hausaufgabe freigibt.'),
            ],
            'LessonAssistant::simplify' => [
                'title' => __('Einfache Sprache'),
                'sent' => __('Fach, Thema und ein Text der Lehrkraft: die Zusammenfassung oder eine Frage.'),
                'notSent' => __('Antworten und Zusammenfassungen der Schüler:innen, und wer Einfache Sprache eingeschaltet hat (das merkt sich nur der Browser).'),
                'result' => __('Der Text in Einfacher Sprache. Er wird an der Stunde oder Frage gespeichert, bis die Lehrkraft den Text ändert.'),
            ],
            'LessonAssistant::askFollowUp' => [
                'title' => __('„Erklär’s mir“: Rückfragen von Tuto'),
                'sent' => __('Das Thema und das bisherige Gespräch, bei einer gewählten Stunde deren Zusammenfassung und Notizen als Hintergrund.'),
                'notSent' => __('Arbeitsblätter und andere Gespräche.'),
                'result' => __('Tutos nächste Rückfrage, die die Schüler:in sofort sieht.'),
            ],
            'LessonAssistant::assessExplanation' => [
                'title' => __('„Erklär’s mir“: Einschätzung am Ende'),
                'sent' => __('Das Thema und das ganze Gespräch, bei einer gewählten Stunde deren Zusammenfassung und Notizen als Hintergrund.'),
                'notSent' => __('Arbeitsblätter und andere Gespräche.'),
                'result' => __('Verständniswert und Rückmeldung. Keine Note.'),
            ],
            'Transcriber::transcribe' => [
                'title' => __('Tuto-Mikro: Spracherkennung'),
                'sent' => __('Kurze Ton-Abschnitte (etwa :seconds Sekunden) aus dem Mikro der Lehrkraft, nur wenn für alle angehakten Schüler:innen auch diesem Zweck zugestimmt wurde. Die Ton-Abschnitte selbst lassen sich nicht pseudonymisieren: Darin sind echte Stimmen, auch gesprochene Namen.', ['seconds' => (int) config('lernplattform.recordings.segment_seconds')]),
                'notSent' => __('Nichts davon wird gespeichert, weder Ton noch Text (der Server kann einen Abschnitt für die Dauer der Anfrage kurz zwischenpuffern). Ohne KI-Funktionen verlässt der Ton das Gerät der Lehrkraft nicht.'),
                'result' => __('Der erkannte Text. Er liegt nur im Browser der Lehrkraft.'),
            ],
            'RecordingAnalyst::analyse' => [
                'title' => __('Tuto-Mikro: Zusammenfassung'),
                'sent' => __('Die Notizen aus der Stunde, so wie die Lehrkraft sie gelesen hat, mit ersetzten Namen, dazu Fach und Thema.'),
                'notSent' => __('Ton.'),
                'result' => __('Zusammenfassung, Fachbegriffe, Tafelbild-Vorschlag und Fragen aus der Klasse. Gespeichert wird nur, was die Lehrkraft in die Stundennotizen übernimmt.'),
            ],
        ];
    }

    /**
     * The AI status as a callout above the page text, so it is the first thing anyone reads.
     */
    public function content(Schema $schema): Schema
    {
        $isOperator = auth()->user()?->isOperator() ?? false;

        $callout = self::isAiEnabled()
            ? Callout::make(__('KI-Funktionen eingeschaltet'))
                ->description($isOperator
                    ? __('Der Schalter der Plattform ist an. Texte gehen aber nur für Schulen an :provider, deren Schuladministration die KI-Funktionen selbst eingeschaltet hat.', ['provider' => $this->providerLabel()])
                    : __('Pseudonymisierte Texte und Arbeitsblätter deiner Schule gehen an :provider. Namen der Klasse werden vorher ersetzt, Benutzernamen und E-Mail-Adressen gehen nie mit.', ['provider' => $this->providerLabel()]))
                ->warning()
                ->icon(Heroicon::OutlinedCloudArrowUp)
            : Callout::make(__('KI-Funktionen ausgeschaltet'))
                ->description(__('Alle Auswertungen laufen mit einer regelbasierten Demo-Logik auf der Plattform. Es geht nichts an einen KI-Anbieter.'))
                ->success()
                ->icon(Heroicon::OutlinedShieldCheck);

        return $schema->components([$callout]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $user = auth()->user();

        return [
            'aiEnabled' => self::isAiEnabled(),
            'platformAiEnabled' => (bool) config('lernplattform.ai.enabled'),
            'providerLabel' => $this->providerLabel(),
            'model' => config('lernplattform.ai.model') ?: __('Standardmodell des Anbieters'),
            'aiFunctions' => self::aiFunctions(),
            'aiChecklist' => array_column(AiSettings::checklistItems(), 'label'),
            'aiSettingsUrl' => AiSettings::canAccess() ? AiSettings::getUrl() : null,
            'sessionKeepDays' => (int) config('lernplattform.recordings.session_keep_days'),
            'notificationReadDays' => (int) config('lernplattform.notifications.keep_read_days'),
            'notificationMaxDays' => (int) config('lernplattform.notifications.keep_days'),
            'retention' => PrivacyDocuments::retentionText(app()->getLocale()),
            'isAdmin' => $user?->isAdmin() ?? false,
            'isOperator' => $user?->isOperator() ?? false,
            'isStudent' => $user?->isStudent() ?? false,
            'roles' => Role::cases(),
            'dataCategories' => self::dataCategories(),
            'levels' => self::levels(),
        ];
    }

    private function providerLabel(): string
    {
        return AiSettings::providerLabel((string) config('lernplattform.ai.provider'));
    }

    /**
     * What is stored per area and which roles see it, in line with the policies in app/Policies.
     *
     * @return list<array{title: string, icon: Heroicon, stored: string, viewers: list<array{role: Role, label: string, icon: Heroicon}>, notes: list<string>}>
     */
    private static function dataCategories(): array
    {
        $teacher = ['role' => Role::Teacher, 'label' => __('Lehrkraft der Stunde'), 'icon' => Heroicon::OutlinedAcademicCap];
        $classTeachers = ['role' => Role::Teacher, 'label' => __('Lehrkräfte der Klasse'), 'icon' => Heroicon::OutlinedAcademicCap];
        $admin = ['role' => Role::Admin, 'label' => __('Schuladministration (eigene Schule)'), 'icon' => Heroicon::OutlinedKey];
        $operator = ['role' => Role::Operator, 'icon' => Heroicon::OutlinedBuildingLibrary];
        $student = ['role' => Role::Student, 'icon' => Heroicon::OutlinedBookOpen];

        return [
            [
                'title' => __('Unterrichtsnotizen und Arbeitsblätter'),
                'icon' => Heroicon::OutlinedDocumentText,
                'stored' => __('Fach, Thema, Datum, Notizen (auch aus dem Tuto-Mikro übernommene), hochgeladene Arbeitsblätter und die Zusammenfassung der Lehrkraft.'),
                'viewers' => [$teacher, $admin],
                'notes' => [__('Schüler:innen sehen weder Notizen noch Arbeitsblätter, sondern nur die Zusammenfassung der Lehrkraft, sobald die Hausaufgabe freigegeben ist.')],
            ],
            [
                'title' => __('Hausaufgaben'),
                'icon' => Heroicon::OutlinedClipboardDocumentList,
                'stored' => __('Die Fragen in bis zu drei Stufen (Einstieg, Standard, Profi) mit der zugeordneten Kompetenz, auf Wunsch auch in Einfacher Sprache. Die KI macht einen Entwurf, die Lehrkraft prüft ihn und gibt ihn für die Klasse oder ausgewählte Schüler:innen frei.'),
                'viewers' => [$teacher, $admin, [...$student, 'label' => __('Zugewiesene Schüler:innen')]],
                'notes' => [
                    __('Schüler:innen sehen eine Hausaufgabe erst, wenn sie freigegeben und ihnen zugewiesen ist.'),
                    __('Der Erwartungshorizont, also was eine gute Antwort enthält, ist nur für Lehrkraft und Schuladministration sichtbar.'),
                    __('Ob jemand Einfache Sprache eingeschaltet hat, merkt sich nur der eigene Browser.'),
                ],
            ],
            [
                'title' => __('Abgaben und KI-Einschätzung'),
                'icon' => Heroicon::OutlinedChartBar,
                'stored' => __('Die eigene Zusammenfassung, die Antworten mit der gewählten Stufe, der Verständniswert von 0 bis 100, die Rückmeldung und Hinweise, was noch wiederholt werden sollte.'),
                'viewers' => [[...$student, 'label' => __('Schüler:in selbst')], $teacher, $admin],
                'notes' => [
                    __('Andere Schüler:innen sehen eine Abgabe nie.'),
                    __('Den Lernstand einer Klasse sehen die Lehrkräfte, die sie leiten, unterrichten oder gerade vertreten.'),
                ],
            ],
            [
                'title' => __('„Erklär’s mir“-Gespräche und Rückfragen'),
                'icon' => Heroicon::OutlinedChatBubbleLeftRight,
                'stored' => __('Das gewählte Thema, das Gespräch mit Tuto, Verständniswert und Rückmeldung. Rückfragen an die Lehrkraft mit ihrer Antwort.'),
                'viewers' => [[...$student, 'label' => __('Schüler:in selbst')], $teacher, $admin],
                'notes' => [__('Lehrkraft und Schuladministration sehen nur Gespräche und Rückfragen zu einer Stunde. Gespräche zu einem frei gewählten Thema sieht nur die Schüler:in selbst.')],
            ],
            [
                'title' => __('Klassenbuch'),
                'icon' => Heroicon::OutlinedBookmarkSquare,
                'stored' => __('Was in einer Stunde unterrichtet wurde, die Hausaufgabe, wer gefehlt hat und Bemerkungen.'),
                'viewers' => [$classTeachers, $admin],
                'notes' => [__('Schüler:innen sehen das Klassenbuch nicht. Eine Vertretung sieht es nur, solange die Vertretung dauert.')],
            ],
            [
                'title' => __('Einwilligungen und Tuto-Mikro'),
                'icon' => Heroicon::OutlinedPencilSquare,
                'stored' => __('Die Einwilligungen der Eltern zum Tuto-Mikro (welche Zwecke, wann, welche Fassung) und je Sitzung ein Nachweis: wann das Mikro an war und wer angehakt war. Ton wird nie gespeichert.'),
                'viewers' => [$classTeachers, $admin, [...$student, 'label' => __('Schüler:in (eigene Einwilligung)')]],
                'notes' => [__('Den Nachweis einer Sitzung sieht nur die Lehrkraft der Stunde, nicht die Schuladministration. Schüler:innen finden die eigene Anwesenheit in ihrem Datenexport.')],
            ],
            [
                'title' => __('Kontodaten'),
                'icon' => Heroicon::OutlinedUserCircle,
                'stored' => __('Name, Benutzername, E-Mail-Adresse, Rolle, Schule, Klasse und die gewählte Sprache. Das Passwort wird nur verschlüsselt (als Hash) gespeichert.'),
                'viewers' => [$admin, [...$operator, 'label' => __('Plattform-Administration (nur Schuladministrationen)')]],
                'notes' => [
                    __('Lehrkräfte sehen die Namen der Schüler:innen der Klassen, die sie leiten, unterrichten oder vertreten.'),
                    __('Hinweise in der Glocke sieht nur, wer sie bekommt.'),
                    __('Wird ein Schülerkonto gelöscht, werden auch seine Abgaben, Gespräche und Einwilligungen gelöscht.'),
                ],
            ],
            [
                'title' => __('Schule und Registrierung'),
                'icon' => Heroicon::OutlinedBuildingLibrary,
                'stored' => __('Name, Ort und Bundesland der Schule, auf Wunsch Schulnummer, Schulform und Kontakt-E-Mail-Adresse, dazu der Zeitpunkt der Registrierung und einer möglichen Sperrung, und ob, wann und von wem die KI-Funktionen eingeschaltet wurden.'),
                'viewers' => [$admin, [...$operator, 'label' => __('Plattform-Administration (alle Schulen)')]],
                'notes' => [__('Eine gesperrte Schule kann die Plattform nicht mehr nutzen. Ihre Daten bleiben gespeichert, bis sie gelöscht werden oder die Sperre aufgehoben wird.')],
            ],
        ];
    }

    /**
     * Where each understanding level starts. Keep in sync with UnderstandingLevel::fromScore(), which the test checks.
     *
     * @return list<array{level: UnderstandingLevel, range: string}>
     */
    private static function levels(): array
    {
        return [
            ['level' => UnderstandingLevel::Understood, 'range' => __('ab 75 von 100')],
            ['level' => UnderstandingLevel::Partly, 'range' => __('50 bis 74 von 100')],
            ['level' => UnderstandingLevel::NeedsSupport, 'range' => __('unter 50 von 100')],
        ];
    }
}
