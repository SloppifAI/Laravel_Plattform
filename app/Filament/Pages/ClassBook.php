<?php

namespace App\Filament\Pages;

use App\Filament\NavigationGroup;
use App\Models\ClassBookEntry;
use App\Models\Lesson;
use App\Models\SchoolClass;
use App\Models\User;
use App\Role;
use App\Support\ClassBookCsv;
use App\Support\ClassBookDraft;
use BackedEnum;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\In as InRule;
use Livewire\Attributes\Url;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

/**
 * "Klassenbuch": what was taught in a class, the homework and who was absent. An entry starts as a draft made from a
 * lesson and is confirmed by the teacher, which locks it (the administration can reopen it). Teachers see the classes
 * they teach, the school administration every class of its school; students never see this page, because the entries
 * name absent students (ClassBookEntryPolicy).
 */
class ClassBook extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.class-book';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Unterricht;

    protected static ?int $navigationSort = 3;

    protected static ?string $title = 'Klassenbuch';

    protected static ?string $slug = 'klassenbuch';

    protected ?string $subheading = 'Was in der Klasse unterrichtet wurde, die Hausaufgaben und wer gefehlt hat. Tuto schreibt den Entwurf aus der Stunde vor, du prüfst und bestätigst ihn.';

    #[Url(as: 'klasse')]
    public ?int $classId = null;

    private ?SchoolClass $resolvedClass = null;

    /**
     * @var Collection<int, string>|null
     */
    private ?Collection $studentNames = null;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user !== null && $user->can('viewAny', ClassBookEntry::class);
    }

    public function mount(): void
    {
        $this->classId ??= $this->allowedClasses()->value('id');

        // A class of someone else's school (or of a colleague's) in the URL is a 403, not an empty page.
        $this->schoolClass();
    }

    public function updatedClassId(): void
    {
        $this->resolvedClass = $this->studentNames = null;
        $this->schoolClass();
        $this->resetPage($this->getTablePaginationPageName());
    }

    /**
     * The classes the signed-in user keeps the Klassenbuch of: a teacher the ones they lead or are assigned to, the
     * administration all of its school.
     *
     * @return Builder<SchoolClass>
     */
    public function allowedClasses(): Builder
    {
        $user = auth()->user();

        return ($user->isAdmin()
            ? SchoolClass::query()->where('school_id', $user->school_id)
            : SchoolClass::query()->taughtBy($user))->orderBy('name');
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

        abort_if($schoolClass === null || ! Gate::allows('accessClass', [ClassBookEntry::class, $schoolClass]), 403);

        return $this->resolvedClass = $schoolClass;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => ClassBookEntry::query()
                ->where('school_class_id', $this->schoolClass()?->getKey() ?? 0)
                ->orderByDesc('held_on')
                ->orderByDesc('period')
                ->orderByDesc('id'))
            ->columns([
                TextColumn::make('held_on')
                    ->label('Datum')
                    ->date('d.m.Y')
                    ->weight(FontWeight::Medium)
                    ->description(fn (ClassBookEntry $record): ?string => $record->period !== null ? "{$record->period}. Stunde" : null),
                TextColumn::make('subject')
                    ->label('Fach und Thema')
                    ->weight(FontWeight::Medium)
                    ->description(fn (ClassBookEntry $record): string => $record->topic)
                    ->wrap(),
                TextColumn::make('content')
                    ->label('Unterrichtsinhalt')
                    ->placeholder('Inhalt fehlt')
                    ->wrap(),
                TextColumn::make('homework')
                    ->label('Hausaufgabe')
                    ->placeholder('–')
                    ->wrap(),
                TextColumn::make('absent')
                    ->label('Fehlt')
                    ->state(fn (ClassBookEntry $record): string => $this->absentNames($record))
                    ->placeholder('–')
                    ->wrap(),
                TextColumn::make('status')
                    ->label('Status')
                    ->state(fn (ClassBookEntry $record) => $record->status())
                    ->badge(),
            ])
            ->paginated([10, 25, 50])
            ->emptyStateHeading('Noch keine Einträge')
            ->emptyStateDescription('Erstelle den ersten Entwurf aus einer deiner Stunden: Tuto übernimmt Fach, Thema und Datum.')
            ->recordActions([
                $this->editAction(),
                $this->confirmAction(),
                $this->reopenAction(),
            ]);
    }

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            $this->createDraftAction(),
            $this->exportAction(),
        ];
    }

    public function createDraftAction(): Action
    {
        return Action::make('createDraft')
            ->label('Entwurf aus Stunde erstellen')
            ->icon(Heroicon::OutlinedDocumentPlus)
            ->authorize(fn (): bool => $this->schoolClass() !== null && Gate::allows('create', ClassBookEntry::class))
            ->modalHeading('Entwurf aus einer Stunde erstellen')
            ->modalDescription('Tuto übernimmt Fach, Thema und Datum, schreibt den Inhalt aus der Zusammenfassung oder den Notizen vor und trägt freigegebene Hausaufgaben ein. Wer fehlt, kommt aus der bestätigten Anwesenheitsliste von Tuto-Mikro, wenn es eine gibt: Schüler:innen, die nicht angehakt waren, aber noch keine Einwilligung haben, trägt Tuto nicht als fehlend ein, das machst du von Hand. Prüfe alles, bevor du bestätigst.')
            ->modalSubmitActionLabel('Entwurf erstellen')
            ->modalCancelActionLabel('Abbrechen')
            ->schema([
                Select::make('lesson_id')
                    ->label('Stunde')
                    ->options(fn (): array => $this->lessonsOfClass()
                        ->whereDoesntHave('classBookEntry')
                        ->orderByDesc('held_on')
                        ->orderByDesc('id')
                        ->get()
                        ->mapWithKeys(fn (Lesson $lesson): array => [$lesson->getKey() => "{$lesson->held_on->format('d.m.Y')} · {$lesson->subject} · {$lesson->topic}"])
                        ->all())
                    ->searchable()
                    ->required()
                    ->helperText('Es stehen die Stunden zur Auswahl, für die es noch keinen Eintrag gibt.'),
            ])
            ->action(function (array $data): void {
                $lesson = $this->lessonsOfClass()->find($data['lesson_id']);

                abort_if($lesson === null || ! Gate::allows('createFromLesson', [ClassBookEntry::class, $lesson]), 403);

                if (! ClassBookDraft::createFor($lesson)->wasRecentlyCreated) {
                    Notification::make()->warning()->title('Für diese Stunde gibt es schon einen Eintrag')->send();

                    return;
                }

                $this->studentNames = null;

                Notification::make()->success()->title('Entwurf erstellt')->body('Prüfe den Eintrag und bestätige ihn, wenn alles stimmt.')->send();
            });
    }

    public function exportAction(): Action
    {
        return Action::make('export')
            ->label('CSV exportieren')
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->color('gray')
            ->authorize(fn (): bool => $this->schoolClass() !== null)
            ->modalHeading('Klassenbuch als CSV exportieren')
            ->modalDescription('Die Datei enthält Entwürfe und bestätigte Einträge des gewählten Zeitraums, auch die Namen fehlender Schüler:innen. Gib sie nur an Berechtigte weiter.')
            ->modalSubmitActionLabel('Exportieren')
            ->modalCancelActionLabel('Abbrechen')
            ->schema([
                Grid::make(['default' => 1, 'sm' => 2])->schema([
                    DatePicker::make('from')
                        ->label('Von')
                        ->default(today()->startOfMonth())
                        ->required()
                        ->native(false)
                        ->displayFormat('d.m.Y'),
                    DatePicker::make('until')
                        ->label('Bis')
                        ->default(today())
                        ->required()
                        ->native(false)
                        ->displayFormat('d.m.Y')
                        ->afterOrEqual('from'),
                ]),
            ])
            ->action(function (array $data): StreamedResponse {
                $schoolClass = $this->schoolClass();

                abort_if($schoolClass === null, 403);

                $from = Carbon::parse($data['from']);
                $until = Carbon::parse($data['until']);
                $csv = ClassBookCsv::render($schoolClass, $from, $until);

                return response()->streamDownload(
                    function () use ($csv): void {
                        echo $csv;
                    },
                    sprintf('klassenbuch-%s-%s-bis-%s.csv', Str::slug($schoolClass->name), $from->format('Y-m-d'), $until->format('Y-m-d')),
                    ['Content-Type' => 'text/csv; charset=UTF-8'],
                );
            });
    }

    public function editAction(): Action
    {
        return Action::make('edit')
            ->label('Bearbeiten')
            ->icon(Heroicon::OutlinedPencilSquare)
            ->authorize('update')
            ->modalHeading(fn (ClassBookEntry $record): string => "Eintrag vom {$record->held_on->format('d.m.Y')} bearbeiten")
            ->modalWidth(Width::ThreeExtraLarge)
            ->modalSubmitActionLabel('Speichern')
            ->modalCancelActionLabel('Abbrechen')
            ->fillForm(fn (ClassBookEntry $record): array => [
                ...$record->only(['period', 'subject', 'topic', 'content', 'homework', 'remarks']),
                'held_on' => $record->held_on->toDateString(),
                'absent_student_ids' => $record->absent_student_ids ?? [],
            ])
            ->schema([
                Grid::make(['default' => 1, 'sm' => 2])->schema([
                    DatePicker::make('held_on')
                        ->label('Datum')
                        ->required()
                        ->native(false)
                        ->displayFormat('d.m.Y'),
                    TextInput::make('period')
                        ->label('Unterrichtsstunde (1 bis 12)')
                        ->integer()
                        ->minValue(1)
                        ->maxValue(12),
                    TextInput::make('subject')
                        ->label('Fach')
                        ->required()
                        ->maxLength(100),
                    TextInput::make('topic')
                        ->label('Thema')
                        ->required()
                        ->maxLength(255),
                ]),
                Textarea::make('content')
                    ->label('Unterrichtsinhalt')
                    ->required()
                    ->rows(4)
                    ->maxLength(5000),
                Textarea::make('homework')
                    ->label('Hausaufgabe')
                    ->rows(2)
                    ->maxLength(2000),
                CheckboxList::make('absent_student_ids')
                    ->label('Fehlende Schüler:innen')
                    ->options(fn (ClassBookEntry $record): array => $this->absenceOptions($record))
                    ->nestedRecursiveRule(fn (ClassBookEntry $record): InRule => Rule::in(array_keys($this->absenceOptions($record))))
                    ->validationMessages(['in' => 'Diese Person gehört nicht zur Klasse.'])
                    ->columns(['default' => 1, 'sm' => 2])
                    ->helperText('Nur die Lehrkräfte der Klasse und die Schuladministration sehen, wer fehlt.'),
                Textarea::make('remarks')
                    ->label('Bemerkungen')
                    ->rows(2)
                    ->maxLength(2000),
            ])
            ->action(function (array $data, ClassBookEntry $record): void {
                abort_unless(Gate::allows('update', $record), 403);

                $record->update([
                    ...Arr::only($data, ['held_on', 'period', 'subject', 'topic', 'content', 'homework', 'remarks']),
                    'absent_student_ids' => array_map(intval(...), $data['absent_student_ids'] ?? []),
                ]);

                $this->studentNames = null;

                Notification::make()->success()->title('Eintrag gespeichert')->send();
            });
    }

    public function confirmAction(): Action
    {
        return Action::make('confirm')
            ->label('Bestätigen')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->authorize('confirm')
            ->disabled(fn (ClassBookEntry $record): bool => ! $record->isComplete())
            ->tooltip(fn (ClassBookEntry $record): ?string => $record->isComplete() ? null : 'Trage zuerst Fach, Thema und Unterrichtsinhalt ein.')
            ->requiresConfirmation()
            ->modalHeading('Eintrag bestätigen?')
            ->modalDescription('Danach lässt sich der Eintrag nicht mehr ändern. Nur die Schuladministration kann ihn wieder öffnen.')
            ->modalSubmitActionLabel('Ja, bestätigen')
            ->modalCancelActionLabel('Noch nicht')
            ->action(function (ClassBookEntry $record): void {
                abort_unless(Gate::allows('confirm', $record), 403);

                $record->forceFill(['confirmed_at' => now()])->save();

                Notification::make()->success()->title('Eintrag bestätigt')->send();
            });
    }

    public function reopenAction(): Action
    {
        return Action::make('reopen')
            ->label('Wieder öffnen')
            ->icon(Heroicon::OutlinedLockOpen)
            ->color('gray')
            ->authorize('reopen')
            ->requiresConfirmation()
            ->modalHeading('Eintrag wieder öffnen?')
            ->modalDescription('Der Eintrag wird wieder zum Entwurf. Die Lehrkraft kann ihn dann ändern und neu bestätigen.')
            ->modalSubmitActionLabel('Ja, öffnen')
            ->modalCancelActionLabel('Abbrechen')
            ->action(function (ClassBookEntry $record): void {
                abort_unless(Gate::allows('reopen', $record), 403);

                $record->forceFill(['confirmed_at' => null])->save();

                Notification::make()->success()->title('Eintrag wieder geöffnet')->send();
            });
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return [
            'classes' => $this->allowedClasses()->get(['id', 'name']),
            'schoolClass' => $this->schoolClass(),
            'isAdmin' => auth()->user()->isAdmin(),
        ];
    }

    /**
     * The lessons of the selected class the user may make a draft from: a teacher's own, all of them for the
     * administration.
     *
     * @return Builder<Lesson>
     */
    private function lessonsOfClass(): Builder
    {
        $user = auth()->user();

        return Lesson::query()
            ->where('school_class_id', $this->schoolClass()?->getKey() ?? 0)
            ->when(! $user->isAdmin(), fn (Builder $lessons): Builder => $lessons->where('teacher_id', $user->getKey()));
    }

    /**
     * The students of the class plus those already marked absent in the entry, who may have left the class since:
     * saving must not drop them silently.
     *
     * @return array<int, string>
     */
    private function absenceOptions(ClassBookEntry $entry): array
    {
        return User::query()
            ->where('school_id', $entry->schoolClass->school_id)
            ->where(fn (Builder $users): Builder => $users
                ->where(fn (Builder $students): Builder => $students->withRole(Role::Student)->where('school_class_id', $entry->school_class_id))
                ->orWhereKey($entry->absent_student_ids ?? []))
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    private function absentNames(ClassBookEntry $entry): string
    {
        return collect($entry->absent_student_ids ?? [])
            ->map(fn (int $id): ?string => $this->studentNames()->get($id))
            ->filter()
            ->sort()
            ->implode(', ');
    }

    /**
     * Names of everybody who is marked absent anywhere in the selected class, loaded once per request.
     *
     * @return Collection<int, string>
     */
    private function studentNames(): Collection
    {
        $schoolClass = $this->schoolClass();

        return $this->studentNames ??= User::query()
            ->where('school_id', $schoolClass?->school_id ?? 0)
            ->whereKey(ClassBookEntry::query()->where('school_class_id', $schoolClass?->getKey() ?? 0)->pluck('absent_student_ids')->flatten()->unique()->all())
            ->pluck('name', 'id');
    }
}
