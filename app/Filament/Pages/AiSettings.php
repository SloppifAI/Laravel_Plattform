<?php

namespace App\Filament\Pages;

use App\Filament\NavigationGroup;
use App\Models\School;
use App\Models\User;
use BackedEnum;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;
use UnitEnum;

/**
 * "KI-Funktionen": the school administration decides whether the texts of its school go to the AI provider. Until it
 * confirms the whole checklist and switches the AI on, the offline demo logic answers (see SchoolAwareLessonAssistant).
 * The school is always the one of the signed-in administration, so no request can touch another school.
 */
class AiSettings extends Page
{
    protected string $view = 'filament.pages.ai-settings';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Verwaltung;

    protected static ?int $navigationSort = 9;

    protected static ?string $title = 'KI-Funktionen';

    protected static ?string $slug = 'ki-funktionen';

    protected ?string $subheading = 'Ob Texte aus dem Unterricht deiner Schule an einen KI-Anbieter gehen dürfen.';

    /**
     * @var array<string, mixed>|null
     */
    public ?array $settingsData = [];

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user !== null && $user->isAdmin() && $user->school_id !== null;
    }

    public function mount(): void
    {
        $school = $this->school();

        $this->settingsForm->fill([
            'checklist' => collect(School::AI_CHECKLIST)->mapWithKeys(fn (string $item): array => [$item => (bool) ($school->ai_checklist[$item] ?? false)])->all(),
        ]);
    }

    /**
     * What each confirmation means, shown next to its checkbox: the school confirms these, the platform cannot check them.
     *
     * @return array<string, array{label: string, help: string}>
     */
    public static function checklistItems(): array
    {
        return [
            'dpa_with_provider' => [
                'label' => 'Auftragsverarbeitung mit dem KI-Anbieter abgeschlossen',
                'help' => 'Der Anbieter verarbeitet Texte deiner Schule in deinem Auftrag. Dafür liegt ein Vertrag zur Auftragsverarbeitung (AVV, Art. 28 DSGVO) mit ihm vor, und er ist im AVV mit der Plattform als Unterauftragsverarbeiter genannt.',
            ],
            'paid_tier' => [
                'label' => 'Bezahlter Tarif, bei dem Eingaben nicht zur Produktverbesserung genutzt werden',
                'help' => 'Im kostenlosen Tarif von Gemini darf Google Eingaben zur Verbesserung seiner Produkte nutzen. Texte von Kindern gehören dort nicht hin. Bestätige, dass die Plattform einen bezahlten Tarif nutzt, bei dem das ausgeschlossen ist.',
            ],
            'dsfa_done' => [
                'label' => 'Datenschutz-Folgenabschätzung durchgeführt',
                'help' => 'Die KI-Funktionen sind in der Datenschutz-Folgenabschätzung der Schule (Art. 35 DSGVO) bewertet und dokumentiert, mit der oder dem Datenschutzbeauftragten abgestimmt.',
            ],
            'parents_informed' => [
                'label' => 'Eltern und Schüler:innen informiert',
                'help' => 'Eltern und Schüler:innen wissen, dass Texte aus dem Unterricht pseudonymisiert an einen KI-Anbieter gehen, zum Beispiel über die Elterninformation unter „Datenschutz-Unterlagen“.',
            ],
        ];
    }

    /**
     * The provider's name as the school administration knows it.
     */
    public static function providerLabel(string $provider): string
    {
        return match ($provider) {
            'gemini' => 'Google Gemini',
            'anthropic' => 'Anthropic Claude',
            'openai' => 'OpenAI',
            'mistral' => 'Mistral AI',
            default => ucfirst($provider),
        };
    }

    public function settingsForm(Schema $schema): Schema
    {
        return $schema
            ->components(collect(self::checklistItems())->map(fn (array $item, string $key): Checkbox => Checkbox::make("checklist.{$key}")
                ->label($item['label'])
                ->helperText($item['help'])
                ->live()
            )->values()->all())
            ->statePath('settingsData');
    }

    /**
     * Saves the checklist. When the AI is on and a confirmation is taken back, it goes off.
     */
    public function saveSettings(): void
    {
        $this->persistChecklist();

        $school = $this->school();

        if ($school->ai_enabled_at !== null && ! $school->isAiChecklistComplete()) {
            $school->disableAi();

            Notification::make()->warning()->title('KI-Funktionen ausgeschaltet')->body('Eine Bestätigung der Checkliste fehlt, deshalb antwortet wieder die Demo-Logik.')->send();

            return;
        }

        Notification::make()->success()->title('Gespeichert')->send();
    }

    public function enableAiAction(): Action
    {
        return Action::make('enableAi')
            ->label('KI-Funktionen einschalten')
            ->icon(Heroicon::OutlinedSparkles)
            ->requiresConfirmation()
            ->modalHeading('KI-Funktionen einschalten?')
            ->modalDescription('Danach gehen pseudonymisierte Texte und Arbeitsblätter deiner Schule an '.self::providerLabel($this->provider()).'. Du kannst die KI-Funktionen jederzeit wieder ausschalten.')
            ->modalSubmitActionLabel('Ja, einschalten')
            ->modalCancelActionLabel('Noch nicht')
            ->visible(fn (): bool => $this->school()->ai_enabled_at === null)
            ->disabled(fn (): bool => $this->missingSteps() !== [])
            ->action(function (): void {
                $this->persistChecklist();

                try {
                    $this->school()->enableAi($this->actor());
                } catch (DomainException) {
                    Notification::make()->danger()->title('Noch nicht möglich')->body('Bestätige zuerst alle Punkte der Checkliste.')->send();

                    return;
                }

                Notification::make()->success()->title('KI-Funktionen sind eingeschaltet')->send();
            });
    }

    public function disableAiAction(): Action
    {
        return Action::make('disableAi')
            ->label('KI-Funktionen ausschalten')
            ->icon(Heroicon::OutlinedStopCircle)
            ->color('gray')
            ->requiresConfirmation()
            ->modalHeading('KI-Funktionen ausschalten?')
            ->modalDescription('Danach geht nichts mehr an den KI-Anbieter. Entwürfe und Auswertungen entstehen wieder mit der Demo-Logik auf der Plattform. Bereits gespeicherte Ergebnisse bleiben bestehen.')
            ->modalSubmitActionLabel('Ja, ausschalten')
            ->modalCancelActionLabel('Abbrechen')
            ->visible(fn (): bool => $this->school()->ai_enabled_at !== null)
            ->action(function (): void {
                $this->school()->disableAi();

                Notification::make()->success()->title('KI-Funktionen sind ausgeschaltet')->send();
            });
    }

    /**
     * The checklist items that still block switching the AI on, from what is on screen.
     *
     * @return list<string>
     */
    public function missingSteps(): array
    {
        return collect(self::checklistItems())
            ->reject(fn (array $item, string $key): bool => (bool) ($this->settingsData['checklist'][$key] ?? false))
            ->map(fn (array $item): string => $item['label'])
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $school = $this->school();
        $provider = $this->provider();

        return [
            'school' => $school,
            'enabledBy' => $school->aiEnabledBy,
            'switchedOn' => $school->ai_enabled_at !== null,
            'aiActive' => $school->aiEnabled(),
            'platformOn' => (bool) config('lernplattform.ai.enabled'),
            'providerLabel' => self::providerLabel($provider),
            'model' => config('lernplattform.ai.model'),
            'missingSteps' => $this->missingSteps(),
        ];
    }

    /**
     * Validates and stores the checklist of the signed-in administration's school. The columns are not mass assignable.
     */
    private function persistChecklist(): void
    {
        $state = $this->settingsForm->getState();

        $this->school()->forceFill([
            'ai_checklist' => collect(School::AI_CHECKLIST)->mapWithKeys(fn (string $item): array => [$item => (bool) ($state['checklist'][$item] ?? false)])->all(),
        ])->save();
    }

    private function provider(): string
    {
        return (string) config('lernplattform.ai.provider');
    }

    private function actor(): User
    {
        return auth()->user() ?? abort(403);
    }

    /**
     * The school of the signed-in administration; every access to it is authorized again.
     */
    private function school(): School
    {
        $user = $this->actor();

        abort_unless($user->isAdmin() && $user->school_id !== null, 403);

        $school = $user->school ?? abort(403);

        Gate::authorize('update', $school);

        return $school;
    }
}
