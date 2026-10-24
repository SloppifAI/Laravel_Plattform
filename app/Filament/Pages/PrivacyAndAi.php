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

    protected static ?string $title = 'Datenschutz & KI';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static ?int $navigationSort = 100;

    protected ?string $subheading = 'Was Tuto speichert, wer es sieht und was die KI damit macht.';

    protected string $view = 'filament.pages.privacy-and-ai';

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
        $segmentSeconds = (int) config('lernplattform.recordings.segment_seconds');

        return [
            'LessonAssistant::draftHomework' => [
                'title' => 'Hausaufgabe entwerfen',
                'sent' => 'Fach, Thema, Datum, Zusammenfassung und Notizen der Lehrkraft (auch übernommene Notizen aus der Stunde), die Kompetenzen des Fachs und angehängte Arbeitsblätter (Fotos, PDF).',
                'notSent' => 'Texte der Schüler:innen.',
                'result' => 'Eine Zusammenfassung und fünf Fragen als Entwurf. Die Lehrkraft prüft ihn, bevor sie ihn freigibt.',
            ],
            'LessonAssistant::draftPracticeTask' => [
                'title' => 'Förderaufgabe entwerfen',
                'sent' => 'Fach, Kompetenz, anonyme Hinweise auf Lücken, dazu Thema, Datum und Notizen der Stunden, in denen sich die Lücke gezeigt hat.',
                'notSent' => 'Arbeitsblätter, Antworten der Schüler:innen und wer die Lücke hatte.',
                'result' => 'Drei bis vier Übungsfragen. Mit „Direkt freigeben“ gehen sie ohne getrennte Prüfung an die ausgewählten Schüler:innen.',
            ],
            'LessonAssistant::evaluateSubmission' => [
                'title' => 'Abgabe einschätzen',
                'sent' => 'Die Stunde wie beim Entwurf mit Arbeitsblättern, die Fragen mit Erwartungshorizont und der gewählten Stufe, die Zusammenfassung und die Antworten der Schüler:in.',
                'notSent' => 'Andere Abgaben und alles, was zum Konto gehört.',
                'result' => 'Verständniswert von 0 bis 100, Rückmeldung, bis zu drei Hinweise auf Lücken und Punkte je Antwort. Die Schüler:in sieht das Ergebnis gleich nach der Abgabe.',
            ],
            'LessonAssistant::differentiateQuestion' => [
                'title' => 'Stufen einer Frage',
                'sent' => 'Fach, Thema, Zusammenfassung der Lehrkraft, Art und Text der Frage.',
                'notSent' => 'Erwartungshorizont, Notizen, Arbeitsblätter und Texte der Schüler:innen.',
                'result' => 'Eine leichtere Fassung (Einstieg) und eine anspruchsvollere (Profi). Die Lehrkraft prüft sie, bevor sie die Hausaufgabe freigibt.',
            ],
            'LessonAssistant::simplify' => [
                'title' => 'Einfache Sprache',
                'sent' => 'Fach, Thema und ein Text der Lehrkraft: die Zusammenfassung oder eine Frage.',
                'notSent' => 'Antworten und Zusammenfassungen der Schüler:innen, und wer Einfache Sprache eingeschaltet hat (das merkt sich nur der Browser).',
                'result' => 'Der Text in Einfacher Sprache. Er wird an der Stunde oder Frage gespeichert, bis die Lehrkraft den Text ändert.',
            ],
            'LessonAssistant::askFollowUp' => [
                'title' => '„Erklär’s mir“: Rückfragen von Tuto',
                'sent' => 'Das Thema und das bisherige Gespräch, bei einer gewählten Stunde deren Zusammenfassung und Notizen als Hintergrund.',
                'notSent' => 'Arbeitsblätter und andere Gespräche.',
                'result' => 'Tutos nächste Rückfrage, die die Schüler:in sofort sieht.',
            ],
            'LessonAssistant::assessExplanation' => [
                'title' => '„Erklär’s mir“: Einschätzung am Ende',
                'sent' => 'Das Thema und das ganze Gespräch, bei einer gewählten Stunde deren Zusammenfassung und Notizen als Hintergrund.',
                'notSent' => 'Arbeitsblätter und andere Gespräche.',
                'result' => 'Verständniswert und Rückmeldung. Keine Note.',
            ],
            'Transcriber::transcribe' => [
                'title' => 'Tuto-Mikro: Spracherkennung',
                'sent' => "Kurze Ton-Abschnitte (etwa {$segmentSeconds} Sekunden) aus dem Mikro der Lehrkraft, nur wenn für alle angehakten Schüler:innen auch diesem Zweck zugestimmt wurde. Die Ton-Abschnitte selbst lassen sich nicht pseudonymisieren: Darin sind echte Stimmen, auch gesprochene Namen.",
                'notSent' => 'Nichts davon wird gespeichert, weder Ton noch Text (der Server kann einen Abschnitt für die Dauer der Anfrage kurz zwischenpuffern). Ohne KI-Funktionen verlässt der Ton das Gerät der Lehrkraft nicht.',
                'result' => 'Der erkannte Text. Er liegt nur im Browser der Lehrkraft.',
            ],
            'RecordingAnalyst::analyse' => [
                'title' => 'Tuto-Mikro: Zusammenfassung',
                'sent' => 'Die Notizen aus der Stunde, so wie die Lehrkraft sie gelesen hat, mit ersetzten Namen, dazu Fach und Thema.',
                'notSent' => 'Ton.',
                'result' => 'Zusammenfassung, Fachbegriffe, Tafelbild-Vorschlag und Fragen aus der Klasse. Gespeichert wird nur, was die Lehrkraft in die Stundennotizen übernimmt.',
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
            ? Callout::make('KI-Funktionen eingeschaltet')
                ->description($isOperator
                    ? 'Der Schalter der Plattform ist an. Texte gehen aber nur für Schulen an '.$this->providerLabel().', deren Schuladministration die KI-Funktionen selbst eingeschaltet hat.'
                    : 'Pseudonymisierte Texte und Arbeitsblätter deiner Schule gehen an '.$this->providerLabel().'. Namen der Klasse werden vorher ersetzt, Benutzernamen und E-Mail-Adressen gehen nie mit.')
                ->warning()
                ->icon(Heroicon::OutlinedCloudArrowUp)
            : Callout::make('KI-Funktionen ausgeschaltet')
                ->description('Alle Auswertungen laufen mit einer regelbasierten Demo-Logik auf der Plattform. Es geht nichts an einen KI-Anbieter.')
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
            'model' => config('lernplattform.ai.model') ?: 'Standardmodell des Anbieters',
            'aiFunctions' => self::aiFunctions(),
            'aiChecklist' => array_column(AiSettings::checklistItems(), 'label'),
            'aiSettingsUrl' => AiSettings::canAccess() ? AiSettings::getUrl() : null,
            'sessionKeepDays' => (int) config('lernplattform.recordings.session_keep_days'),
            'notificationReadDays' => (int) config('lernplattform.notifications.keep_read_days'),
            'notificationMaxDays' => (int) config('lernplattform.notifications.keep_days'),
            'retention' => PrivacyDocuments::retentionText(),
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
        $teacher = ['role' => Role::Teacher, 'label' => 'Lehrkraft der Stunde', 'icon' => Heroicon::OutlinedAcademicCap];
        $classTeachers = ['role' => Role::Teacher, 'label' => 'Lehrkräfte der Klasse', 'icon' => Heroicon::OutlinedAcademicCap];
        $admin = ['role' => Role::Admin, 'label' => 'Schuladministration (eigene Schule)', 'icon' => Heroicon::OutlinedKey];
        $operator = ['role' => Role::Operator, 'icon' => Heroicon::OutlinedBuildingLibrary];
        $student = ['role' => Role::Student, 'icon' => Heroicon::OutlinedBookOpen];

        return [
            [
                'title' => 'Unterrichtsnotizen und Arbeitsblätter',
                'icon' => Heroicon::OutlinedDocumentText,
                'stored' => 'Fach, Thema, Datum, Notizen (auch aus dem Tuto-Mikro übernommene), hochgeladene Arbeitsblätter und die Zusammenfassung der Lehrkraft.',
                'viewers' => [$teacher, $admin],
                'notes' => ['Schüler:innen sehen weder Notizen noch Arbeitsblätter, sondern nur die Zusammenfassung der Lehrkraft, sobald die Hausaufgabe freigegeben ist.'],
            ],
            [
                'title' => 'Hausaufgaben',
                'icon' => Heroicon::OutlinedClipboardDocumentList,
                'stored' => 'Die Fragen in bis zu drei Stufen (Einstieg, Standard, Profi) mit der zugeordneten Kompetenz, auf Wunsch auch in Einfacher Sprache. Die KI macht einen Entwurf, die Lehrkraft prüft ihn und gibt ihn für die Klasse oder ausgewählte Schüler:innen frei.',
                'viewers' => [$teacher, $admin, [...$student, 'label' => 'Zugewiesene Schüler:innen']],
                'notes' => [
                    'Schüler:innen sehen eine Hausaufgabe erst, wenn sie freigegeben und ihnen zugewiesen ist.',
                    'Der Erwartungshorizont, also was eine gute Antwort enthält, ist nur für Lehrkraft und Schuladministration sichtbar.',
                    'Ob jemand Einfache Sprache eingeschaltet hat, merkt sich nur der eigene Browser.',
                ],
            ],
            [
                'title' => 'Abgaben und KI-Einschätzung',
                'icon' => Heroicon::OutlinedChartBar,
                'stored' => 'Die eigene Zusammenfassung, die Antworten mit der gewählten Stufe, der Verständniswert von 0 bis 100, das Feedback und Hinweise, was noch wiederholt werden sollte.',
                'viewers' => [[...$student, 'label' => 'Schüler:in selbst'], $teacher, $admin],
                'notes' => [
                    'Andere Schüler:innen sehen eine Abgabe nie.',
                    'Den Lernstand einer Klasse sehen die Lehrkräfte, die sie leiten, unterrichten oder gerade vertreten.',
                ],
            ],
            [
                'title' => '„Erklär’s mir“-Gespräche und Rückfragen',
                'icon' => Heroicon::OutlinedChatBubbleLeftRight,
                'stored' => 'Das gewählte Thema, das Gespräch mit Tuto, Verständniswert und Feedback. Rückfragen an die Lehrkraft mit ihrer Antwort.',
                'viewers' => [[...$student, 'label' => 'Schüler:in selbst'], $teacher, $admin],
                'notes' => ['Lehrkraft und Schuladministration sehen nur Gespräche und Rückfragen zu einer Stunde. Gespräche zu einem frei gewählten Thema sieht nur die Schüler:in selbst.'],
            ],
            [
                'title' => 'Klassenbuch',
                'icon' => Heroicon::OutlinedBookmarkSquare,
                'stored' => 'Was in einer Stunde unterrichtet wurde, die Hausaufgabe, wer gefehlt hat und Bemerkungen.',
                'viewers' => [$classTeachers, $admin],
                'notes' => ['Schüler:innen sehen das Klassenbuch nicht. Eine Vertretung sieht es nur, solange die Vertretung dauert.'],
            ],
            [
                'title' => 'Einwilligungen und Tuto-Mikro',
                'icon' => Heroicon::OutlinedPencilSquare,
                'stored' => 'Die Einwilligungen der Eltern zum Tuto-Mikro (welche Zwecke, wann, welche Fassung) und je Sitzung ein Nachweis: wann das Mikro an war und wer angehakt war. Ton wird nie gespeichert.',
                'viewers' => [$classTeachers, $admin, [...$student, 'label' => 'Schüler:in (eigene Einwilligung)']],
                'notes' => ['Den Nachweis einer Sitzung sieht nur die Lehrkraft der Stunde, nicht die Schuladministration. Schüler:innen finden die eigene Anwesenheit in ihrem Datenexport.'],
            ],
            [
                'title' => 'Kontodaten',
                'icon' => Heroicon::OutlinedUserCircle,
                'stored' => 'Name, Benutzername, E-Mail-Adresse, Rolle, Schule, Klasse und die gewählte Sprache. Das Passwort wird nur verschlüsselt (als Hash) gespeichert.',
                'viewers' => [$admin, [...$operator, 'label' => 'Plattform-Administration (nur Schuladministrationen)']],
                'notes' => [
                    'Lehrkräfte sehen die Namen der Schüler:innen der Klassen, die sie leiten, unterrichten oder vertreten.',
                    'Hinweise in der Glocke sieht nur, wer sie bekommt.',
                    'Wird ein Schülerkonto gelöscht, werden auch seine Abgaben, Gespräche und Einwilligungen gelöscht.',
                ],
            ],
            [
                'title' => 'Schule und Registrierung',
                'icon' => Heroicon::OutlinedBuildingLibrary,
                'stored' => 'Name, Ort und Bundesland der Schule, auf Wunsch Schulnummer, Schulform und Kontakt-E-Mail-Adresse, dazu der Zeitpunkt der Registrierung und einer möglichen Sperrung, und ob, wann und von wem die KI-Funktionen eingeschaltet wurden.',
                'viewers' => [$admin, [...$operator, 'label' => 'Plattform-Administration (alle Schulen)']],
                'notes' => ['Eine gesperrte Schule kann die Plattform nicht mehr nutzen. Ihre Daten bleiben gespeichert, bis sie gelöscht werden oder die Sperre aufgehoben wird.'],
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
            ['level' => UnderstandingLevel::Understood, 'range' => 'ab 75 von 100'],
            ['level' => UnderstandingLevel::Partly, 'range' => '50 bis 74 von 100'],
            ['level' => UnderstandingLevel::NeedsSupport, 'range' => 'unter 50 von 100'],
        ];
    }
}
