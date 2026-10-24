<?php

namespace App\Filament\Pages;

use App\Filament\NavigationGroup;
use App\Models\ConsentForm;
use App\Models\School;
use App\Models\User;
use App\Role;
use BackedEnum;
use Closure;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use UnitEnum;

/**
 * "Tuto-Mikro & Einwilligungen": the school administration decides whether the Tuto-Mikro may be switched on in lessons
 * of its school. It captures the non-public spoken word (§ 201 StGB) and needs a legal basis, so it stays off until the
 * checklist is confirmed and a consent form is published. The school is always the one of the signed-in administration.
 */
class RecordingSettings extends Page
{
    protected string $view = 'filament.pages.recording-settings';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSignal;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Verwaltung;

    protected static ?int $navigationSort = 8;

    protected static ?string $slug = 'tuto-mikro-einstellungen';

    public static function getNavigationLabel(): string
    {
        return __('Tuto-Mikro & Einwilligungen');
    }

    public function getTitle(): string
    {
        return __('Tuto-Mikro & Einwilligungen');
    }

    public function getSubheading(): string
    {
        return __('Ob Lehrkräfte das Tuto-Mikro im Unterricht einschalten dürfen, und welchen Text die Eltern dafür bestätigen.');
    }

    /**
     * @var array<string, mixed>|null
     */
    public ?array $settingsData = [];

    /**
     * @var array<string, mixed>|null
     */
    public ?array $formData = [];

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user !== null && $user->can('viewAny', ConsentForm::class);
    }

    public function mount(): void
    {
        $school = $this->school();

        $this->settingsForm->fill([
            'checklist' => collect(School::RECORDING_CHECKLIST)->mapWithKeys(fn (string $item): array => [$item => (bool) ($school->recording_checklist[$item] ?? false)])->all(),
            'require_student_consent' => $school->require_student_consent,
            'mikro_blocked_subjects' => $school->mikroBlockedSubjects(),
        ]);

        $this->fillConsentFormEditor();
    }

    /**
     * What each confirmation means, shown next to its checkbox: the school confirms these, the platform cannot check them.
     *
     * @return array<string, array{label: string, help: string}>
     */
    public static function checklistItems(): array
    {
        $days = (int) config('lernplattform.recordings.session_keep_days', 365);

        return [
            'dsfa_done' => [
                'label' => __('Datenschutz-Folgenabschätzung durchgeführt'),
                'help' => __('Das Tuto-Mikro erfasst den Ton von Kindern im Unterricht, das ist eine Verarbeitung mit hohem Risiko. Vor dem Start braucht die Schule eine dokumentierte Datenschutz-Folgenabschätzung (Art. 35 DSGVO).'),
            ],
            'personalrat_agreed' => [
                'label' => __('Personalrat beteiligt'),
                'help' => __('Das Tuto-Mikro erfasst auch die Stimmen der Lehrkräfte. Der Personalrat wurde beteiligt oder hat zugestimmt, soweit das Landesrecht es verlangt.'),
            ],
            'parents_informed' => [
                'label' => __('Eltern vorab informiert'),
                'help' => __('Die Eltern wissen, dass die Schule das Tuto-Mikro einsetzen will, zum Beispiel vom Elternabend oder aus einem Brief. Die Einwilligung selbst holst du über die Elternbriefe unter „Einwilligungen“ ein.'),
            ],
            'consent_form_approved' => [
                'label' => __('Einwilligungserklärung geprüft und freigegeben'),
                'help' => __('Der veröffentlichte Text unten wurde mit der oder dem Datenschutzbeauftragten abgestimmt und passt zu eurer Schule. Nach jeder neuen Fassung bestätigst du das erneut.'),
            ],
            'retention_set' => [
                'label' => __('Aufbewahrungsfrist festgelegt'),
                'help' => __('Das Tuto-Mikro speichert keinen Ton. Der Nachweis je Sitzung wird nach :days Tagen automatisch gelöscht, übernommene Notizen folgen den Fristen der Unterrichtsdaten. Die Fristen stehen im Text der Einwilligung und passen zum Löschkonzept der Schule.', ['days' => $days]),
            ],
        ];
    }

    public function settingsForm(Schema $schema): Schema
    {
        return $schema
            ->components([
                ...collect(self::checklistItems())->map(fn (array $item, string $key): Checkbox => Checkbox::make("checklist.{$key}")
                    ->label($item['label'])
                    ->helperText($item['help'])
                    ->live()
                )->values()->all(),
                Toggle::make('require_student_consent')
                    ->label(__('Schüler:innen bestätigen zusätzlich selbst'))
                    ->helperText(__('Je nach Bundesland und Schule reicht die Einwilligung der Eltern nicht, wenn die Schüler:innen schon einsichtsfähig sind. Dann bestätigt jede Schüler:in unter „Tuto-Mikro im Unterricht“ ihr Einverständnis, bevor in ihrer Klasse das Tuto-Mikro an ist.')),
                TagsInput::make('mikro_blocked_subjects')
                    ->label(__('Fächer ohne Tuto-Mikro'))
                    ->helperText(__('In diesen Fächern lässt sich das Tuto-Mikro nie einschalten, auch nicht mit Einwilligung: Dort geht es oft um Glauben, Überzeugungen oder Persönliches. Gesperrt ist eine Stunde, wenn ein Wort ihres Fachs so beginnt, Groß- und Kleinschreibung egal: „Religion“ sperrt auch „Katholische Religion“ und „Religionslehre“. Kurze Kürzel wie „LER“ müssen als ganzes Wort dastehen. Weitere Fächer schreibst du einfach dazu, mit Enter.'))
                    ->suggestions(School::DEFAULT_MIKRO_BLOCKED_SUBJECTS)
                    ->nestedRecursiveRules(['string', 'max:100'])
                    ->rules(['max:50']),
            ])
            ->statePath('settingsData');
    }

    public function consentFormForm(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('title')
                    ->label(__('Überschrift'))
                    ->required()
                    ->maxLength(150),
                Textarea::make('body')
                    ->label(__('Text der Einwilligung'))
                    ->helperText(__('Markdown: „## Überschrift“, „- Aufzählung“, „**fett**“. HTML wird entfernt. Die Vorlage ist ein Entwurf und keine Rechtsberatung: Passe sie mit deiner oder deinem Datenschutzbeauftragten an.'))
                    ->required()
                    ->rows(18)
                    ->maxLength(20000),
                CheckboxList::make('purposes')
                    ->label(__('Zwecke, denen die Eltern einzeln zustimmen'))
                    ->options(array_map(fn (string $label): string => __($label), ConsentForm::PURPOSES))
                    ->helperText(__('Der Ton ist immer dabei. Die Notizen aus der Stunde und die KI-Spracherkennung bauen darauf auf.'))
                    ->required()
                    ->rules([fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                        if (! in_array('audio', (array) $value, true)) {
                            $fail(__('Der Ton gehört immer dazu: Notizen aus der Stunde und KI-Spracherkennung bauen darauf auf.'));
                        }
                    }]),
            ])
            ->statePath('formData');
    }

    /**
     * Saves the checklist and the student switch. When recordings are on and a confirmation is taken back, they go off.
     */
    public function saveSettings(): void
    {
        $this->persistSettings();

        $school = $this->school();

        if ($school->recordings_enabled_at !== null && ! $school->isRecordingChecklistComplete()) {
            $school->disableRecordings();

            Notification::make()->warning()->title(__('Tuto-Mikro ausgeschaltet'))->body(__('Eine Bestätigung der Checkliste fehlt, deshalb ist das Tuto-Mikro wieder aus.'))->send();

            return;
        }

        Notification::make()->success()->title(__('Gespeichert'))->send();
    }

    public function enableRecordingsAction(): Action
    {
        return Action::make('enableRecordings')
            ->label(__('Tuto-Mikro freischalten'))
            ->icon(Heroicon::OutlinedSignal)
            ->requiresConfirmation()
            ->modalHeading(__('Tuto-Mikro freischalten?'))
            ->modalDescription(__('Danach dürfen Lehrkräfte das Tuto-Mikro in ihrer Stunde einschalten, sobald für alle anwesenden Schüler:innen eine gültige Einwilligung vorliegt. Du kannst es jederzeit wieder ausschalten.'))
            ->modalSubmitActionLabel(__('Ja, freischalten'))
            ->modalCancelActionLabel(__('Noch nicht'))
            ->visible(fn (): bool => $this->school()->recordings_enabled_at === null)
            ->disabled(fn (): bool => $this->missingSteps() !== [])
            ->action(function (): void {
                $this->persistSettings();

                try {
                    $this->school()->enableRecordings($this->actor());
                } catch (DomainException) {
                    Notification::make()->danger()->title(__('Noch nicht möglich'))->body(__('Bestätige zuerst alle Punkte der Checkliste und veröffentliche eine Einwilligungserklärung.'))->send();

                    return;
                }

                Notification::make()->success()->title(__('Tuto-Mikro ist freigeschaltet'))->send();
            });
    }

    public function disableRecordingsAction(): Action
    {
        return Action::make('disableRecordings')
            ->label(__('Tuto-Mikro ausschalten'))
            ->icon(Heroicon::OutlinedStopCircle)
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading(__('Tuto-Mikro ausschalten?'))
            ->modalDescription(__('Lehrkräfte können das Tuto-Mikro dann nicht mehr einschalten. Vorhandene Einwilligungen bleiben bestehen, vorhandener Ton und vorhandene Notizen aus der Stunde werden nach der üblichen Frist gelöscht.'))
            ->modalSubmitActionLabel(__('Ja, ausschalten'))
            ->modalCancelActionLabel(__('Abbrechen'))
            ->visible(fn (): bool => $this->school()->recordings_enabled_at !== null)
            ->action(function (): void {
                Gate::authorize('create', [ConsentForm::class, $this->school()]);

                $this->school()->disableRecordings();

                Notification::make()->success()->title(__('Tuto-Mikro ist ausgeschaltet'))->send();
            });
    }

    /**
     * Publishes the text as a new version. A version is never edited afterwards, so every consent stays traceable to
     * the exact text; consents for older versions become outdated and the parents confirm again.
     */
    public function publishConsentFormAction(): Action
    {
        return Action::make('publishConsentForm')
            ->label(fn (): string => $this->school()->currentConsentForm() === null ? __('Als Version 1 veröffentlichen') : __('Als neue Version veröffentlichen'))
            ->icon(Heroicon::OutlinedDocumentCheck)
            ->requiresConfirmation()
            ->modalHeading(__('Neue Fassung veröffentlichen?'))
            ->modalDescription(fn (): string => $this->school()->currentConsentForm() === null
                ? __('Die Eltern sehen diesen Text unter dem Link im Elternbrief.')
                : __('Alle bisherigen Einwilligungen gelten dann als veraltet, und die Eltern müssen dem neuen Text noch einmal zustimmen. Bis dahin bleibt das Tuto-Mikro aus. Außerdem nimmst du die Bestätigung „Einwilligungserklärung geprüft“ zurück.'))
            ->modalSubmitActionLabel(__('Ja, veröffentlichen'))
            ->modalCancelActionLabel(__('Noch nicht'))
            ->action(function (): void {
                $school = $this->school();

                Gate::authorize('create', [ConsentForm::class, $school]);

                $data = $this->consentFormForm->getState();
                $purposes = array_values(array_intersect(array_keys(ConsentForm::PURPOSES), ['audio', ...$data['purposes']]));
                $current = $school->currentConsentForm();

                if ($current?->title === $data['title'] && $current->body === $data['body'] && $current->purposes === $purposes) {
                    Notification::make()->info()->title(__('Der Text ist unverändert'))->body(__('Es gibt keine neue Fassung, die Einwilligungen bleiben gültig.'))->send();

                    return;
                }

                $form = DB::transaction(function () use ($school, $data, $purposes): ConsentForm {
                    $form = ConsentForm::query()->create([
                        'school_id' => $school->getKey(),
                        'version' => ((int) ConsentForm::query()->where('school_id', $school->getKey())->max('version')) + 1,
                        'title' => $data['title'],
                        'body' => $data['body'],
                        'purposes' => $purposes,
                        'published_at' => now(),
                        'created_by' => $this->actor()->getKey(),
                    ]);

                    // The new text has not been approved yet.
                    $school->forceFill(['recording_checklist' => [...($school->recording_checklist ?? []), 'consent_form_approved' => false]])->save();

                    return $form;
                });

                $this->mount();

                Notification::make()
                    ->success()
                    ->title(__('Fassung :version veröffentlicht', ['version' => $form->version]))
                    ->body($current === null ? null : __('Bisherige Einwilligungen gelten jetzt als veraltet. Bestätige unter „Voraussetzungen“ erneut, dass der Text geprüft ist.'))
                    ->send();
            });
    }

    /**
     * The checklist items (and the missing consent form) that still block switching recordings on, from what is on screen.
     *
     * @return list<string>
     */
    public function missingSteps(): array
    {
        $missing = collect(self::checklistItems())
            ->reject(fn (array $item, string $key): bool => (bool) ($this->settingsData['checklist'][$key] ?? false))
            ->map(fn (array $item): string => $item['label'])
            ->values();

        if ($this->school()->currentConsentForm() === null) {
            $missing->push(__('Einwilligungserklärung veröffentlichen'));
        }

        return $missing->all();
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $school = $this->school();
        $current = $school->currentConsentForm();
        $studentCount = User::query()->where('school_id', $school->getKey())->withRole(Role::Student)->count();

        return [
            'school' => $school,
            'enabledBy' => $school->recordingsEnabledBy,
            'recordingsEnabled' => $school->recordingsEnabled(),
            'switchedOn' => $school->recordings_enabled_at !== null,
            'current' => $current,
            'versions' => $school->consentForms()->whereNotNull('published_at')->withCount(['consents' => fn (Builder $query): Builder => $query->whereNotNull('granted_at')])->with('creator')->get(),
            'studentCount' => $studentCount,
            'consentedCount' => $current === null ? 0 : $current->consents()->whereNotNull('granted_at')->whereNull('revoked_at')->count(),
            'missingSteps' => $this->missingSteps(),
            'sessionDays' => (int) config('lernplattform.recordings.session_keep_days', 365),
        ];
    }

    /**
     * Validates and stores the checklist and the student switch of the signed-in administration's school.
     */
    private function persistSettings(): void
    {
        $school = $this->school();

        Gate::authorize('create', [ConsentForm::class, $school]);

        $state = $this->settingsForm->getState();

        $school->forceFill([
            'recording_checklist' => collect(School::RECORDING_CHECKLIST)->mapWithKeys(fn (string $item): array => [$item => (bool) ($state['checklist'][$item] ?? false)])->all(),
            'require_student_consent' => (bool) $state['require_student_consent'],
            'mikro_blocked_subjects' => $this->normalisedSubjects($state['mikro_blocked_subjects'] ?? []),
        ])->save();
    }

    /**
     * The subjects as saved: trimmed, without empty entries and without repeats that differ only in case. An empty
     * list stays empty on purpose, it is the school's choice to block nothing.
     *
     * @param  array<int, mixed>  $subjects
     * @return list<string>
     */
    private function normalisedSubjects(array $subjects): array
    {
        return collect($subjects)
            ->map(fn (mixed $subject): string => Str::squish((string) $subject))
            ->filter()
            ->unique(fn (string $subject): string => mb_strtolower($subject))
            ->values()
            ->all();
    }

    /**
     * The editor starts with the current version, or with the template for a school that has none yet.
     */
    private function fillConsentFormEditor(): void
    {
        $current = $this->school()->currentConsentForm();

        $this->consentFormForm->fill([
            'title' => $current?->title ?? ConsentForm::defaultTitle(),
            'body' => $current?->body ?? ConsentForm::defaultBody($this->school()),
            'purposes' => $current?->purposes ?? array_keys(ConsentForm::PURPOSES),
        ]);
    }

    private function actor(): User
    {
        return auth()->user() ?? abort(403);
    }

    private function school(): School
    {
        return $this->actor()->school ?? abort(403);
    }
}
