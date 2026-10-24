<?php

namespace App\Filament\Pages;

use App\ConsentStatus;
use App\Filament\NavigationGroup;
use App\Models\ConsentForm;
use App\Models\RecordingConsent;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\User;
use App\Role;
use BackedEnum;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\TextSize;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * "Einwilligungen": who of a class has consented to the Tuto-Mikro in lessons. Teachers see the classes they teach, the
 * school administration every class of its school (RecordingConsentPolicy::manageClass). From here the parent letters
 * are made, paper consents and revocations are recorded, and the status of every student is visible with a label and an icon.
 */
class ClassConsents extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.class-consents';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Unterricht;

    protected static ?int $navigationSort = 4;

    protected static ?string $slug = 'einwilligungen';

    public static function getNavigationLabel(): string
    {
        return __('Einwilligungen');
    }

    public function getTitle(): string
    {
        return __('Einwilligungen');
    }

    public function getSubheading(): string
    {
        return __('Wer in der Klasse dem Tuto-Mikro im Unterricht zugestimmt hat. Ohne Einwilligung aller Anwesenden bleibt das Mikro aus.');
    }

    #[Url(as: 'klasse')]
    public ?int $classId = null;

    /**
     * @var Collection<int, RecordingConsent>|null
     */
    private ?Collection $consentsByStudent = null;

    private ?SchoolClass $resolvedClass = null;

    private ?ConsentForm $resolvedForm = null;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user !== null && $user->can('viewAny', RecordingConsent::class);
    }

    public function mount(): void
    {
        $this->classId ??= $this->allowedClasses()->value('id');

        // Opening a class of someone else's school (or of a colleague's) through the URL is a 403, not an empty page.
        $this->schoolClass();
    }

    public function updatedClassId(): void
    {
        $this->consentsByStudent = $this->resolvedClass = $this->resolvedForm = null;
        $this->schoolClass();
    }

    /**
     * The classes the signed-in user looks after: a teacher the ones they teach (lead, assigned, substituting), the
     * administration all. Holding a lesson in a class grants nothing.
     *
     * @return Builder<SchoolClass>
     */
    public function allowedClasses(): Builder
    {
        $user = auth()->user();

        return SchoolClass::query()
            ->where('school_id', $user->school_id)
            ->unless($user->isAdmin(), fn (Builder $query) => $query->taughtBy($user))
            ->orderBy('name');
    }

    /**
     * The selected class, authorised on every call: the id comes from the URL and from Livewire state.
     */
    public function schoolClass(): ?SchoolClass
    {
        if ($this->classId === null) {
            return null;
        }

        if ($this->resolvedClass?->getKey() === $this->classId) {
            return $this->resolvedClass;
        }

        $schoolClass = SchoolClass::query()->with('school')->find($this->classId);

        abort_if($schoolClass === null || ! Gate::allows('manageClass', [RecordingConsent::class, $schoolClass]), 403);

        return $this->resolvedClass = $schoolClass;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(function (): Builder {
                $schoolClass = $this->schoolClass();

                return User::query()
                    ->withRole(Role::Student)
                    ->where('school_id', $schoolClass?->school_id ?? 0)
                    ->where('school_class_id', $schoolClass?->getKey() ?? 0);
            })
            ->columns([
                Split::make([
                    Stack::make([
                        TextColumn::make('name')
                            ->label(__('Schüler:in'))
                            ->weight(FontWeight::Medium)
                            ->searchable(),
                        TextColumn::make('consent_detail')
                            ->label(__('Stand'))
                            ->state(fn (User $record): string => $this->detailFor($record))
                            ->color('gray')
                            ->size(TextSize::Small)
                            ->wrap(),
                    ]),
                    TextColumn::make('consent_status')
                        ->label(__('Einwilligung'))
                        ->state(fn (User $record): ConsentStatus => $this->statusFor($record))
                        ->badge()
                        ->grow(false),
                ])->from('md'),
            ])
            ->defaultSort('name')
            ->paginated(false)
            ->emptyStateHeading(__('In dieser Klasse gibt es noch keine Schüler:innen'))
            ->emptyStateDescription(__('Schüler:innen kommen über den Klassencode oder den Import der Schülerliste in die Klasse.'))
            ->recordActions([
                ActionGroup::make([
                    $this->recordPaperConsentAction(),
                    $this->recordRevocationAction(),
                    $this->newLetterAction(),
                ])->label(__('Aktionen'))->icon(Heroicon::OutlinedEllipsisVertical)->color('gray'),
            ]);
    }

    /**
     * Opens the printable letters: a plain POST to the letters route that makes new links and renders the page once.
     * The hidden form of the view is submitted, so the response replaces this page like a normal navigation.
     */
    public function generateLettersAction(): Action
    {
        return Action::make('generateLetters')
            ->label(__('Elternbriefe erzeugen'))
            ->icon(Heroicon::OutlinedPrinter)
            ->modalHeading(__('Elternbriefe erzeugen'))
            ->modalDescription(__('Jeder Brief enthält einen persönlichen Link und einen QR-Code. Die Links siehst du nur direkt nach dem Erzeugen. Ein neuer Link macht den alten ungültig.'))
            ->modalSubmitActionLabel(__('Briefe erzeugen'))
            ->modalCancelActionLabel(__('Abbrechen'))
            ->schema([
                Select::make('scope')
                    ->label(__('Für wen?'))
                    ->options([
                        'new' => __('Nur für Schüler:innen, die noch keinen Brief hatten'),
                        'all' => __('Für die ganze Klasse neu (alle bisherigen Links werden ungültig)'),
                    ])
                    ->default('new')
                    ->required()
                    ->selectablePlaceholder(false),
            ])
            ->disabled(fn (): bool => $this->schoolClass() === null || $this->currentForm() === null)
            ->tooltip(fn (): ?string => $this->currentForm() === null ? __('Die Schuladministration muss zuerst die Einwilligungserklärung veröffentlichen.') : null)
            ->action(function (array $data): void {
                $this->authorizeClass();

                $this->submitLetters($data['scope'] === 'all' ? 'all' : 'new', null);
            });
    }

    public function recordPaperConsentAction(): Action
    {
        return Action::make('recordPaperConsent')
            ->label(__('Papier-Einwilligung erfassen'))
            ->icon(Heroicon::OutlinedDocumentPlus)
            ->modalHeading(fn (User $record): string => __('Papier-Einwilligung für :name', ['name' => $record->name]))
            ->modalDescription(function (User $record): string {
                $text = __('Trage ein, was auf dem unterschriebenen Papier steht. Das Blatt bewahrt die Schule als Nachweis auf (Art. 7 Abs. 1 DSGVO).');
                $consent = $this->consents()->get($record->getKey());
                $latest = $consent?->latestDeclarationAt();

                if ($latest === null) {
                    return $text;
                }

                $date = $latest->format('d.m.Y');

                return $text.' '.($consent->revoked_at !== null
                    ? __('Die Eltern haben zuletzt am :date widerrufen. Die jüngste Erklärung gilt: Das Blatt zählt nur, wenn es an diesem Tag oder später unterschrieben wurde.', ['date' => $date])
                    : __('Die Eltern haben zuletzt am :date eingewilligt. Die jüngste Erklärung gilt: Das Blatt zählt nur, wenn es an diesem Tag oder später unterschrieben wurde.', ['date' => $date]));
            })
            ->modalSubmitActionLabel(__('Einwilligung erfassen'))
            ->modalCancelActionLabel(__('Abbrechen'))
            ->disabled(fn (): bool => $this->currentForm() === null)
            ->schema(fn (User $record): array => [
                TextInput::make('guardian_name')
                    ->label(__('Name der unterschreibenden Person'))
                    ->required()
                    ->minLength(2)
                    ->maxLength(120),
                CheckboxList::make('purposes')
                    ->label(__('Angekreuzte Zwecke'))
                    ->options(fn (): array => array_map(fn (string $label): string => __($label), $this->currentForm()?->purposeLabels() ?? []))
                    ->required()
                    ->rules([fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                        if (! in_array('audio', (array) $value, true)) {
                            $fail(__('Der Ton gehört immer dazu: Ohne ihn ist keine Einwilligung möglich.'));
                        }
                    }]),
                DatePicker::make('given_on')
                    ->label(__('Unterschrieben am'))
                    ->default(today())
                    ->required()
                    ->native(false)
                    ->displayFormat('d.m.Y')
                    ->minDate(fn (User $record): ?string => $this->earliestPaperDate($record)?->toDateString())
                    ->validationMessages(['after_or_equal' => $this->tooEarlyForPaper($record)])
                    ->maxDate(today()),
            ])
            ->action(function (array $data, User $record): void {
                $this->authorizeClass();

                $form = $this->currentForm();

                if ($form === null) {
                    return;
                }

                try {
                    RecordingConsent::forStudent($record)->grant(
                        $form,
                        $data['purposes'],
                        trim($data['guardian_name']),
                        RecordingConsent::SOURCE_PAPER,
                        auth()->user(),
                        Carbon::parse($data['given_on'])->setTimeFrom(now()),
                    );
                } catch (InvalidArgumentException) {
                    // The parents declared something newer after the dialog was opened: the newer declaration stays.
                    $this->consentsByStudent = null;

                    Notification::make()->danger()->title(__('Nicht erfasst'))->body($this->tooEarlyForPaper($record))->persistent()->send();

                    return;
                }

                $this->consentsByStudent = null;

                Notification::make()->success()->title(__('Papier-Einwilligung für :name erfasst', ['name' => $record->name]))->send();
            });
    }

    public function recordRevocationAction(): Action
    {
        return Action::make('recordRevocation')
            ->label(__('Widerruf erfassen'))
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->visible(function (User $record): bool {
                $consent = $this->consents()->get($record->getKey());

                return $consent?->granted_at !== null && $consent->revoked_at === null;
            })
            ->modalHeading(fn (User $record): string => __('Widerruf für :name erfassen', ['name' => $record->name]))
            ->modalDescription(__('Nach dem Widerruf bleibt das Tuto-Mikro in jeder Stunde aus, in der diese Schüler:in dabei ist, bis die Eltern neu einwilligen.'))
            ->modalSubmitActionLabel(__('Widerruf erfassen'))
            ->modalCancelActionLabel(__('Abbrechen'))
            ->schema(fn (User $record): array => [
                DatePicker::make('revoked_on')
                    ->label(__('Widerrufen am'))
                    ->default(today())
                    ->required()
                    ->native(false)
                    ->displayFormat('d.m.Y')
                    ->minDate(fn (User $record): ?string => $this->consents()->get($record->getKey())?->granted_at?->toDateString())
                    ->validationMessages(['after_or_equal' => $this->tooEarlyForRevocation($record)])
                    ->maxDate(today()),
            ])
            ->action(function (array $data, User $record): void {
                $this->authorizeClass();

                try {
                    RecordingConsent::forStudent($record)->revoke(auth()->user(), Carbon::parse($data['revoked_on'])->setTimeFrom(now()));
                } catch (InvalidArgumentException) {
                    $this->consentsByStudent = null;

                    Notification::make()->danger()->title(__('Nicht erfasst'))->body($this->tooEarlyForRevocation($record))->persistent()->send();

                    return;
                }

                $this->consentsByStudent = null;

                Notification::make()->success()->title(__('Widerruf für :name erfasst', ['name' => $record->name]))->send();
            });
    }

    public function newLetterAction(): Action
    {
        return Action::make('newLetter')
            ->label(__('Neuen Brief erzeugen'))
            ->icon(Heroicon::OutlinedPrinter)
            ->requiresConfirmation()
            ->modalHeading(fn (User $record): string => __('Neuen Brief für :name erzeugen?', ['name' => $record->name]))
            ->modalDescription(__('Der bisherige Link dieser Schüler:in wird ungültig. Die Eltern brauchen dann den neuen Brief, auch zum Widerrufen.'))
            ->modalSubmitActionLabel(__('Brief erzeugen'))
            ->modalCancelActionLabel(__('Abbrechen'))
            ->disabled(fn (): bool => $this->currentForm() === null)
            ->action(function (User $record): void {
                $this->authorizeClass();

                $this->submitLetters('new', $record);
            });
    }

    /**
     * The earliest day a paper slip for the student may carry: the day the current text was published or the day the
     * parents last declared their will, whichever is later. An older slip must not override a newer declaration.
     */
    private function earliestPaperDate(User $student): ?CarbonInterface
    {
        return collect([
            $this->currentForm()?->published_at,
            $this->consents()->get($student->getKey())?->latestDeclarationAt(),
        ])->filter()->max();
    }

    /**
     * Why a paper slip with that date is refused, in the words of the case that applies.
     */
    private function tooEarlyForPaper(User $student): string
    {
        $consent = $this->consents()->get($student->getKey());
        $latest = $consent?->latestDeclarationAt();
        $published = $this->currentForm()?->published_at;

        if ($latest === null || ($published !== null && $published->toDateString() > $latest->toDateString())) {
            return __('Der aktuelle Text gilt erst seit dem :date. Ein Blatt, das früher unterschrieben wurde, bezieht sich auf einen anderen Text.', ['date' => $published?->format('d.m.Y') ?? '–']);
        }

        $date = $latest->format('d.m.Y');

        return $consent->revoked_at !== null
            ? __('Die Eltern haben zuletzt am :date widerrufen. Ein Blatt, das früher unterschrieben wurde, kann diese jüngere Erklärung nicht überschreiben.', ['date' => $date])
            : __('Die Eltern haben zuletzt am :date eingewilligt. Ein Blatt, das früher unterschrieben wurde, kann diese jüngere Erklärung nicht überschreiben.', ['date' => $date]);
    }

    /**
     * Why a revocation dated before the consent it revokes is refused.
     */
    private function tooEarlyForRevocation(User $student): string
    {
        $grantedAt = $this->consents()->get($student->getKey())?->granted_at;

        return __('Die Eltern haben am :date eingewilligt. Ein Widerruf, der früher datiert ist, kann diese jüngere Einwilligung nicht aufheben.', ['date' => $grantedAt?->format('d.m.Y') ?? '–']);
    }

    public function statusFor(User $student): ConsentStatus
    {
        return ConsentStatus::for($this->consents()->get($student->getKey()), $this->currentForm(), $this->school()?->require_student_consent ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $schoolClass = $this->schoolClass();
        $students = $schoolClass?->students()->withRole(Role::Student)->get() ?? new Collection;
        $statuses = $students->map(fn (User $student): ConsentStatus => $this->statusFor($student));

        return [
            'classes' => $this->allowedClasses()->get(['id', 'name']),
            'schoolClass' => $schoolClass,
            'school' => $this->school(),
            'form' => $this->currentForm(),
            'total' => $students->count(),
            'grantedCount' => $statuses->filter(fn (ConsentStatus $status): bool => $status === ConsentStatus::Granted)->count(),
            'statusCounts' => collect(ConsentStatus::cases())
                ->reject(fn (ConsentStatus $status): bool => $status === ConsentStatus::Granted)
                ->mapWithKeys(fn (ConsentStatus $status): array => [$status->value => $statuses->filter(fn (ConsentStatus $each): bool => $each === $status)->count()])
                ->filter()
                ->all(),
            'isAdmin' => auth()->user()->isAdmin(),
            'settingsUrl' => RecordingSettings::getUrl(),
        ];
    }

    private function authorizeClass(): void
    {
        abort_if($this->schoolClass() === null, 403);
    }

    /**
     * Hands over to the hidden POST form of the view; `student` limits the letters to one student.
     */
    private function submitLetters(string $scope, ?User $student): void
    {
        $this->js(sprintf(
            'const form = document.getElementById("consent-letters-form"); form.elements.scope.value = %s; form.elements.student.value = %s; form.submit();',
            json_encode($scope),
            json_encode($student?->getKey() ?? ''),
        ));
    }

    private function detailFor(User $student): string
    {
        $consent = $this->consents()->get($student->getKey());
        $status = $this->statusFor($student);

        return match (true) {
            $status === ConsentStatus::Revoked => __('Widerrufen am :date', ['date' => $consent->revoked_at->format('d.m.Y')]),
            $consent?->granted_at !== null => __('Erteilt am :date (:source) für :purposes:version', [
                'date' => $consent->granted_at->format('d.m.Y'),
                'source' => $consent->source === RecordingConsent::SOURCE_PAPER ? __('Papier') : __('online'),
                'purposes' => implode(', ', array_map(fn (string $label): string => __($label), array_intersect_key(ConsentForm::PURPOSES_SHORT, array_flip($consent->purposes ?? [])))),
                'version' => $consent->consentForm !== null && $this->currentForm() !== null && $status === ConsentStatus::Outdated
                    ? __(', Fassung :version (aktuell: :current)', ['version' => $consent->consentForm->version, 'current' => $this->currentForm()->version])
                    : '',
            ]),
            $consent?->token_generated_at !== null => __('Brief erzeugt am :date, noch keine Antwort', ['date' => $consent->token_generated_at->format('d.m.Y')]),
            default => __('Noch kein Brief erzeugt'),
        };
    }

    /**
     * The consent rows of the selected class, loaded once per request.
     *
     * @return Collection<int, RecordingConsent>
     */
    private function consents(): Collection
    {
        return $this->consentsByStudent ??= RecordingConsent::query()
            ->with('consentForm')
            ->where('school_id', $this->schoolClass()?->school_id ?? 0)
            ->whereIn('student_id', User::query()->where('school_class_id', $this->schoolClass()?->getKey() ?? 0)->select('id'))
            ->get()
            ->keyBy('student_id');
    }

    private function school(): ?School
    {
        return $this->schoolClass()?->school;
    }

    private function currentForm(): ?ConsentForm
    {
        return $this->resolvedForm ??= $this->school()?->currentConsentForm();
    }
}
