<?php

namespace App\Filament\Pages;

use App\ConsentStatus;
use App\Models\ConsentForm;
use App\Models\RecordingConsent;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\User;
use App\Role;
use BackedEnum;
use Carbon\Carbon;
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
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * "Einwilligungen": who of a class has consented to lesson recordings. Teachers see the classes they lead or teach, the
 * school administration every class of its school (RecordingConsentPolicy::manageClass). From here the parent letters
 * are made, paper consents and revocations are recorded, and the status of every student is visible with a label and an icon.
 */
class ClassConsents extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.class-consents';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Unterricht';

    protected static ?int $navigationSort = 4;

    protected static ?string $title = 'Einwilligungen';

    protected static ?string $slug = 'einwilligungen';

    protected ?string $subheading = 'Wer in der Klasse der Tonaufnahme im Unterricht zugestimmt hat. Ohne Einwilligung aller Anwesenden wird nicht aufgenommen.';

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
     * The classes the signed-in user looks after: a teacher the ones they lead or have taught, the administration all.
     *
     * @return Builder<SchoolClass>
     */
    public function allowedClasses(): Builder
    {
        $user = auth()->user();

        $query = SchoolClass::query()->where('school_id', $user->school_id)->orderBy('name');

        return $user->isAdmin()
            ? $query
            : $query->where(fn (Builder $query): Builder => $query
                ->where('teacher_id', $user->getKey())
                ->orWhereHas('lessons', fn (Builder $lessons): Builder => $lessons->where('teacher_id', $user->getKey())));
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
                            ->label('Schüler:in')
                            ->weight(FontWeight::Medium)
                            ->searchable(),
                        TextColumn::make('consent_detail')
                            ->label('Stand')
                            ->state(fn (User $record): string => $this->detailFor($record))
                            ->color('gray')
                            ->size(TextSize::Small)
                            ->wrap(),
                    ]),
                    TextColumn::make('consent_status')
                        ->label('Einwilligung')
                        ->state(fn (User $record): ConsentStatus => $this->statusFor($record))
                        ->badge()
                        ->grow(false),
                ])->from('md'),
            ])
            ->defaultSort('name')
            ->paginated(false)
            ->emptyStateHeading('In dieser Klasse gibt es noch keine Schüler:innen')
            ->emptyStateDescription('Schüler:innen kommen über den Klassencode oder den Import der Schülerliste in die Klasse.')
            ->recordActions([
                ActionGroup::make([
                    $this->recordPaperConsentAction(),
                    $this->recordRevocationAction(),
                    $this->newLetterAction(),
                ])->label('Aktionen')->icon(Heroicon::OutlinedEllipsisVertical)->color('gray'),
            ]);
    }

    /**
     * Opens the printable letters: a plain POST to the letters route that makes new links and renders the page once.
     * The hidden form of the view is submitted, so the response replaces this page like a normal navigation.
     */
    public function generateLettersAction(): Action
    {
        return Action::make('generateLetters')
            ->label('Elternbriefe erzeugen')
            ->icon(Heroicon::OutlinedPrinter)
            ->modalHeading('Elternbriefe erzeugen')
            ->modalDescription('Jeder Brief enthält einen persönlichen Link und einen QR-Code. Die Links siehst du nur direkt nach dem Erzeugen. Ein neuer Link macht den alten ungültig.')
            ->modalSubmitActionLabel('Briefe erzeugen')
            ->modalCancelActionLabel('Abbrechen')
            ->schema([
                Select::make('scope')
                    ->label('Für wen?')
                    ->options([
                        'new' => 'Nur für Schüler:innen, die noch keinen Brief hatten',
                        'all' => 'Für die ganze Klasse neu (alle bisherigen Links werden ungültig)',
                    ])
                    ->default('new')
                    ->required()
                    ->selectablePlaceholder(false),
            ])
            ->disabled(fn (): bool => $this->schoolClass() === null || $this->currentForm() === null)
            ->tooltip(fn (): ?string => $this->currentForm() === null ? 'Die Schuladministration muss zuerst die Einwilligungserklärung veröffentlichen.' : null)
            ->action(function (array $data): void {
                $this->authorizeClass();

                $this->submitLetters($data['scope'] === 'all' ? 'all' : 'new', null);
            });
    }

    public function recordPaperConsentAction(): Action
    {
        return Action::make('recordPaperConsent')
            ->label('Papier-Einwilligung erfassen')
            ->icon(Heroicon::OutlinedDocumentPlus)
            ->modalHeading(fn (User $record): string => "Papier-Einwilligung für {$record->name}")
            ->modalDescription(function (User $record): string {
                $text = 'Trage ein, was auf dem unterschriebenen Papier steht. Das Blatt bewahrt die Schule als Nachweis auf (Art. 7 Abs. 1 DSGVO).';
                $consent = $this->consents()->get($record->getKey());
                $latest = $consent?->latestDeclarationAt();

                return $latest === null
                    ? $text
                    : sprintf('%s Die Eltern haben zuletzt am %s %s. Die jüngste Erklärung gilt: Das Blatt zählt nur, wenn es an diesem Tag oder später unterschrieben wurde.', $text, $latest->format('d.m.Y'), $consent->revoked_at !== null ? 'widerrufen' : 'eingewilligt');
            })
            ->modalSubmitActionLabel('Einwilligung erfassen')
            ->modalCancelActionLabel('Abbrechen')
            ->disabled(fn (): bool => $this->currentForm() === null)
            ->schema(fn (): array => [
                TextInput::make('guardian_name')
                    ->label('Name der unterschreibenden Person')
                    ->required()
                    ->minLength(2)
                    ->maxLength(120),
                CheckboxList::make('purposes')
                    ->label('Angekreuzte Zwecke')
                    ->options(fn (): array => $this->currentForm()?->purposeLabels() ?? [])
                    ->required()
                    ->rules([fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                        if (! in_array('audio', (array) $value, true)) {
                            $fail('Die Tonaufnahme gehört immer dazu: Ohne sie ist keine Einwilligung möglich.');
                        }
                    }]),
                DatePicker::make('given_on')
                    ->label('Unterschrieben am')
                    ->default(today())
                    ->required()
                    ->native(false)
                    ->displayFormat('d.m.Y')
                    ->minDate(fn (User $record): ?string => collect([
                        $this->currentForm()?->published_at,
                        $this->consents()->get($record->getKey())?->latestDeclarationAt(),
                    ])->filter()->max()?->toDateString())
                    ->maxDate(today()),
            ])
            ->action(function (array $data, User $record): void {
                $this->authorizeClass();

                $form = $this->currentForm();

                if ($form === null) {
                    return;
                }

                RecordingConsent::forStudent($record)->grant(
                    $form,
                    $data['purposes'],
                    trim($data['guardian_name']),
                    RecordingConsent::SOURCE_PAPER,
                    auth()->user(),
                    Carbon::parse($data['given_on'])->setTimeFrom(now()),
                );

                $this->consentsByStudent = null;

                Notification::make()->success()->title("Papier-Einwilligung für {$record->name} erfasst")->send();
            });
    }

    public function recordRevocationAction(): Action
    {
        return Action::make('recordRevocation')
            ->label('Widerruf erfassen')
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->visible(function (User $record): bool {
                $consent = $this->consents()->get($record->getKey());

                return $consent?->granted_at !== null && $consent->revoked_at === null;
            })
            ->modalHeading(fn (User $record): string => "Widerruf für {$record->name} erfassen")
            ->modalDescription('Nach dem Widerruf wird in keiner Stunde aufgenommen, bei der diese Schüler:in dabei ist, bis die Eltern neu einwilligen.')
            ->modalSubmitActionLabel('Widerruf erfassen')
            ->modalCancelActionLabel('Abbrechen')
            ->schema([
                DatePicker::make('revoked_on')
                    ->label('Widerrufen am')
                    ->default(today())
                    ->required()
                    ->native(false)
                    ->displayFormat('d.m.Y')
                    ->maxDate(today()),
            ])
            ->action(function (array $data, User $record): void {
                $this->authorizeClass();

                RecordingConsent::forStudent($record)->revoke(auth()->user(), Carbon::parse($data['revoked_on'])->setTimeFrom(now()));

                $this->consentsByStudent = null;

                Notification::make()->success()->title("Widerruf für {$record->name} erfasst")->send();
            });
    }

    public function newLetterAction(): Action
    {
        return Action::make('newLetter')
            ->label('Neuen Brief erzeugen')
            ->icon(Heroicon::OutlinedPrinter)
            ->requiresConfirmation()
            ->modalHeading(fn (User $record): string => "Neuen Brief für {$record->name} erzeugen?")
            ->modalDescription('Der bisherige Link dieser Schüler:in wird ungültig. Die Eltern brauchen dann den neuen Brief, auch zum Widerrufen.')
            ->modalSubmitActionLabel('Brief erzeugen')
            ->modalCancelActionLabel('Abbrechen')
            ->disabled(fn (): bool => $this->currentForm() === null)
            ->action(function (User $record): void {
                $this->authorizeClass();

                $this->submitLetters('new', $record);
            });
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
            $status === ConsentStatus::Revoked => 'Widerrufen am '.$consent->revoked_at->format('d.m.Y'),
            $consent?->granted_at !== null => sprintf(
                'Erteilt am %s (%s) für %s%s',
                $consent->granted_at->format('d.m.Y'),
                $consent->source === RecordingConsent::SOURCE_PAPER ? 'Papier' : 'online',
                implode(', ', array_intersect_key(ConsentForm::PURPOSES_SHORT, array_flip($consent->purposes ?? []))),
                $consent->consentForm !== null && $this->currentForm() !== null && $status === ConsentStatus::Outdated ? ", Fassung {$consent->consentForm->version} (aktuell: {$this->currentForm()->version})" : '',
            ),
            $consent?->token_generated_at !== null => 'Brief erzeugt am '.$consent->token_generated_at->format('d.m.Y').', noch keine Antwort',
            default => 'Noch kein Brief erzeugt',
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
