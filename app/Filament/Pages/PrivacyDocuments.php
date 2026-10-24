<?php

namespace App\Filament\Pages;

use App\Models\School;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\HtmlString;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * "Datenschutz-Unterlagen": printable templates a school needs before it uses the platform and before it switches
 * on lesson recordings (checklist, DSFA, Verzeichnis, Elterninformation, Einwilligung). They are prefilled with the
 * master data of the administration's own school and with the real settings of this platform (retention, AI status),
 * so the texts cannot drift from what the code does. They are templates for the school to adapt, not legal advice.
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
     * Whether starting a recording is refused by the platform itself when a present student has no valid consent
     * (RecordingConsentGate). While it is false, the templates say that the teacher checks the consent overview and
     * that the platform does not enforce it. Set to true together with the gate; PrivacyDocumentsTest fails if the two differ.
     */
    public const bool CONSENT_GATE_ENFORCED = false;

    /**
     * What the AI provider receives when the connection is on, as the templates name it. Only the evaluation of a
     * recording replaces names first (Pseudonymizer); everything else goes out as it stands.
     */
    public const string AI_DATA = 'Texte (Notizen der Lehrkraft, Zusammenfassungen, Antworten der Schüler:innen, Gespräche in „Erklär’s mir“) und hochgeladene Arbeitsblatt-Dateien (Fotos, PDF)';

    /**
     * The templates in the order of the tabs; the key is the view name below privacy-documents/ and the URL value.
     *
     * @var array<string, array{label: string, description: string, icon: Heroicon}>
     */
    private const array DOCUMENTS = [
        'checkliste' => ['label' => 'Checkliste', 'description' => 'Was vor dem Start und vor dem Aufnahme-Modul zu klären ist', 'icon' => Heroicon::OutlinedClipboardDocumentCheck],
        'dsfa' => ['label' => 'Folgenabschätzung', 'description' => 'Datenschutz-Folgenabschätzung nach Art. 35 DSGVO', 'icon' => Heroicon::OutlinedScale],
        'verzeichnis' => ['label' => 'Verzeichnis', 'description' => 'Eintrag für das Verzeichnis der Verarbeitungstätigkeiten (Art. 30)', 'icon' => Heroicon::OutlinedListBullet],
        'elterninformation' => ['label' => 'Elterninformation', 'description' => 'Information der Eltern nach Art. 13 DSGVO', 'icon' => Heroicon::OutlinedEnvelope],
        'einwilligung' => ['label' => 'Einwilligung', 'description' => 'Muster-Einwilligung für Tonaufnahmen im Unterricht', 'icon' => Heroicon::OutlinedPencilSquare],
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
        return auth()->user()?->isOperator() ? 'Plattform' : 'Verwaltung';
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $school = auth()->user()->school;

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
            'blank' => new HtmlString('<span class="pd-blank"><span class="sr-only">bitte ausfüllen</span></span>'),
            'printedOn' => now()->format('d.m.Y'),
            'aiEnabled' => PrivacyAndAi::isAiEnabled(),
            'aiData' => self::AI_DATA,
            'consentGateEnforced' => self::CONSENT_GATE_ENFORCED,
            'transcriptionEnabled' => (bool) config('services.transcription.enabled'),
            'recordingDays' => (int) config('lernplattform.recordings.keep_days'),
            'schoolYearsKept' => (int) config('lernplattform.retention.school_years_kept'),
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
