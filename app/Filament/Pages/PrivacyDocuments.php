<?php

namespace App\Filament\Pages;

use App\Filament\NavigationGroup;
use App\Models\ClassSubstitution;
use App\Models\School;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\HtmlString;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * "Datenschutz-Unterlagen": printable templates a school needs before it uses Tuto, before it switches on the AI
 * functions and before it switches on the Tuto-Mikro (checklist, DSFA, Verzeichnis, Elterninformation, Einwilligung).
 * They are prefilled with the master data of the administration's own school and with the real settings of this
 * platform (retention, AI provider and the school's AI switch, Tuto-Mikro switch), so the texts cannot drift from what
 * the code does; tests/Feature/PrivacyDocuments backs the statements about the platform with tests against the code.
 * They are templates for the school to adapt, not legal advice.
 *
 * The school administration gets them with the data of its school; the operator sees the same texts with blank school fields.
 */
class PrivacyDocuments extends Page
{
    /**
     * The operator's contact data, as in the imprint (resources/views/filament/legal/imprint.blade.php).
     *
     * @var array<string, string>
     */
    public const array OPERATOR = [
        'name' => 'Softwareentwicklung Sebastian Bleuel',
        'person' => 'Sebastian Bleuel',
        'street' => 'Ortesweg 10',
        'city' => '36145 Hofbieber',
        'email' => 'info@sebastian-bleuel.de',
    ];

    /**
     * What the AI provider receives from the lesson AI of a school that switched it on, as the templates name it
     * (SdkLessonAssistant): texts pass the Pseudonymizer first, worksheet files cannot.
     */
    public const string AI_DATA = 'pseudonymisierte Texte (Fach, Thema, Notizen und Zusammenfassung der Lehrkraft, Fragen mit Erwartungshorizont, Zusammenfassungen und Antworten der Schüler:innen, Gespräche in „Erklär’s mir“) und Arbeitsblatt-Dateien (Fotos, PDF)';

    /**
     * The templates in the order of the tabs; the key is the view name below privacy-documents/ and the URL value.
     *
     * @var array<string, array{label: string, description: string, icon: Heroicon}>
     */
    private const array DOCUMENTS = [
        'checkliste' => ['label' => 'Checkliste', 'description' => 'Was vor dem Start, vor den KI-Funktionen und vor dem Tuto-Mikro zu klären ist', 'icon' => Heroicon::OutlinedClipboardDocumentCheck],
        'dsfa' => ['label' => 'Folgenabschätzung', 'description' => 'Datenschutz-Folgenabschätzung nach Art. 35 DSGVO', 'icon' => Heroicon::OutlinedScale],
        'verzeichnis' => ['label' => 'Verzeichnis', 'description' => 'Einträge für das Verzeichnis der Verarbeitungstätigkeiten (Art. 30)', 'icon' => Heroicon::OutlinedListBullet],
        'elterninformation' => ['label' => 'Elterninformation', 'description' => 'Information der Eltern nach Art. 13 DSGVO', 'icon' => Heroicon::OutlinedEnvelope],
        'einwilligung' => ['label' => 'Einwilligung', 'description' => 'Muster-Einwilligung für das Tuto-Mikro im Unterricht', 'icon' => Heroicon::OutlinedPencilSquare],
    ];

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentCheck;

    protected static ?int $navigationSort = 7;

    protected static ?string $title = 'Datenschutz-Unterlagen';

    protected static ?string $slug = 'datenschutz-unterlagen';

    protected ?string $subheading = 'Vorlagen zum Ausdrucken und Anpassen: Checkliste, Folgenabschätzung, Verzeichnis, Elterninformation und Einwilligung.';

    protected string $view = 'filament.pages.privacy-documents';

    /**
     * The open template. Only ever used as a key into DOCUMENTS, so any other value shows the checklist.
     */
    #[Url]
    public string $document = 'checkliste';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user !== null && ($user->isOperator() || ($user->isAdmin() && $user->school !== null));
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return auth()->user()?->isOperator() ? NavigationGroup::Plattform : NavigationGroup::Verwaltung;
    }

    /**
     * How long lessons, hand-ins, conversations and Klassenbuch entries are kept, as one German phrase. It follows
     * PruneLearningData: the current school year plus `school_years_kept - 1`, and the transition to one school year.
     */
    public static function retentionText(): string
    {
        $years = (int) config('lernplattform.retention.school_years_kept');
        $starts = CarbonImmutable::createFromFormat('!m-d', (string) config('lernplattform.retention.school_year_starts'))->format('d.m.');
        $oneYearFrom = (string) config('lernplattform.retention.one_year_rule_from');

        if ($years > 1) {
            return "nach {$years} Schuljahren (das laufende eingerechnet), jeweils zu Beginn eines Schuljahres am {$starts}";
        }

        $text = "zum Ende des Schuljahres, also zu Beginn des nächsten Schuljahres am {$starts}";

        return $oneYearFrom !== '' && today()->lt(CarbonImmutable::parse($oneYearFrom))
            ? $text.' (Übergang: bis zum '.CarbonImmutable::parse($oneYearFrom)->format('d.m.Y').' bleiben noch zwei Schuljahre gespeichert)'
            : $text;
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $school = auth()->user()->school;
        $provider = (string) config('lernplattform.ai.provider');

        return [
            'documents' => collect(self::DOCUMENTS)->map(fn (array $item, string $key): array => [...$item, 'url' => self::getUrl(['document' => $key])])->all(),
            'currentDocument' => array_key_exists($this->document, self::DOCUMENTS) ? $this->document : array_key_first(self::DOCUMENTS),
            'school' => $this->schoolFacts($school),
            'operator' => self::OPERATOR,
            'dpa' => [
                'url' => Route::has('legal.dpa') ? route('legal.dpa') : null,
                'acceptedAt' => $school?->hasAcceptedCurrentDpa() ? $school->dpa_accepted_at : null,
            ],
            'recordings' => [
                'enabled' => $school?->recordingsEnabled(),
                'settingsUrl' => RecordingSettings::canAccess() ? RecordingSettings::getUrl() : null,
            ],
            'ai' => [
                // A school's own switch decides (platform switch, opt-in, checklist); the operator sees the platform switch.
                'enabled' => $school === null ? (bool) config('lernplattform.ai.enabled') : $school->aiEnabled(),
                'settingsUrl' => AiSettings::canAccess() ? AiSettings::getUrl() : null,
            ],
            'aiProvider' => AiSettings::providerLabel($provider),
            // Ollama runs the model on a server the operator chooses, not at a third-party provider.
            'aiProviderIsLocal' => $provider === 'ollama',
            'aiData' => self::AI_DATA,
            'blank' => new HtmlString('<span class="pd-blank"><span class="sr-only">bitte ausfüllen</span></span>'),
            'printedOn' => now()->format('d.m.Y'),
            'retention' => self::retentionText(),
            'sessionKeepDays' => (int) config('lernplattform.recordings.session_keep_days'),
            'segmentSeconds' => (int) config('lernplattform.recordings.segment_seconds'),
            'maxSessionHours' => (int) config('lernplattform.recordings.max_session_hours'),
            'blockedSubjects' => $school?->mikroBlockedSubjects() ?? School::DEFAULT_MIKRO_BLOCKED_SUBJECTS,
            'substitutionGrants' => ClassSubstitution::grants('de'),
            'substitutionMaxDays' => ClassSubstitution::maxDays(),
            'notificationReadDays' => (int) config('lernplattform.notifications.keep_read_days'),
            'notificationMaxDays' => (int) config('lernplattform.notifications.keep_days'),
            'sessionMinutes' => (int) config('session.lifetime'),
        ];
    }

    /**
     * Master data of the school; a missing value stays null and the template shows a line to fill in.
     *
     * @return array{name: ?string, number: ?string, type: ?string, city: ?string, state: ?string, email: ?string}
     */
    private function schoolFacts(?School $school): array
    {
        return [
            'name' => $school?->name,
            'number' => $school?->school_number,
            'type' => $school?->school_type,
            'city' => $school?->city,
            'state' => $school?->state,
            'email' => $school?->contact_email,
        ];
    }
}
