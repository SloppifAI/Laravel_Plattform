<?php

namespace App\Filament\Pages;

use App\Filament\NavigationGroup;
use App\Filament\Resources\Lessons\LessonResource;
use App\Models\Lesson;
use App\Models\User;
use App\Support\HomeworkDraft;
use App\Support\LessonRelease;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Throwable;
use UnitEnum;

/**
 * "Heute": the teacher's lessons of today and the last two school days with what is still to do, so the homework goes
 * out in 30 seconds: draft it with the AI, check it, release it, one lesson at a time or all that are ready at once.
 * The list only ever contains the teacher's own lessons; every action authorises through LessonPolicy (update/publish).
 */
class Today extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.today';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSun;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Unterricht;

    protected static ?int $navigationSort = 0;

    protected static ?string $slug = 'heute';

    /**
     * How many school days (Monday to Friday) before today still show up.
     */
    private const int SCHOOL_DAYS_BACK = 2;

    public static function getNavigationLabel(): string
    {
        return __('Heute');
    }

    public function getTitle(): string
    {
        return __('Heute');
    }

    public function getSubheading(): string
    {
        return __('Deine Stunden von heute und den letzten Schultagen: Hausaufgabe erstellen, prüfen, freigeben.');
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->isTeacher() ?? false;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('releaseAll')
                ->label(__('Alle bereiten freigeben'))
                ->icon(Heroicon::OutlinedPaperAirplane)
                ->visible(fn (): bool => $this->releasableLessons()->isNotEmpty())
                ->requiresConfirmation()
                ->modalHeading(__('Alle bereiten Hausaufgaben freigeben?'))
                ->modalDescription(function (): string {
                    $lessons = $this->releasableLessons();

                    return __(':waiting: :lessons. Jede geht an die ganze Klasse. Die Schüler:innen finden die Fragen danach in ihrem Lernbereich, den Erwartungshorizont sehen sie nicht.', [
                        'waiting' => trans_choice(':count Hausaufgabe mit Fragen wartet auf Freigabe|:count Hausaufgaben mit Fragen warten auf Freigabe', $lessons->count()),
                        'lessons' => $lessons->map(fn (Lesson $lesson): string => "{$lesson->subject} ({$lesson->schoolClass->name})")->implode(', '),
                    ]);
                })
                ->modalSubmitActionLabel(__('Freigeben'))
                ->schema([$this->dueOnField()])
                ->action(function (array $data): void {
                    $teacher = $this->teacher();
                    $dueOn = Carbon::parse($data['due_on']);

                    // Fresh and re-checked: the list on screen may be a while old, and each lesson is authorised on its own.
                    $reached = $this->releasableLessons()->map(fn (Lesson $lesson): int => LessonRelease::release($lesson, $teacher, $dueOn));
                    $released = $reached->filter()->count();

                    if ($released === 0) {
                        Notification::make()
                            ->warning()
                            ->title(__('Niemand freigegeben'))
                            ->body(__('In den Klassen sind noch keine Schüler:innen eingetragen.'))
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->success()
                        ->title(trans_choice(':count Hausaufgabe freigegeben|:count Hausaufgaben freigegeben', $released))
                        ->body(($released < $reached->count() ? __('Klassen ohne Schüler:innen wurden übersprungen.').' ' : '').__('Abgabe bis :date.', ['date' => $dueOn->format('d.m.Y')]))
                        ->send();
                }),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => $this->lessonsQuery()->with('schoolClass.school')->withCount(['questions', 'students', 'submissions']))
            ->defaultSort('held_on', 'desc')
            ->paginated(false)
            ->columns([
                TextColumn::make('topic')
                    ->label(__('Stunde'))
                    ->description(fn (Lesson $record): string => __(':day · :subject · Klasse :class', [
                        'day' => $record->held_on->isToday() ? __('Heute') : $record->held_on->locale(app()->getLocale())->isoFormat(__('dd., D.M.')),
                        'subject' => $record->subject,
                        'class' => $record->schoolClass->name,
                    ]), position: 'above')
                    ->weight(FontWeight::Medium)
                    ->wrap(),
                TextColumn::make('status')
                    ->label(__('Status'))
                    ->state(fn (Lesson $record): string => $record->isPublished() ? __('Freigegeben') : __('Wartet auf Freigabe'))
                    ->badge()
                    ->color(fn (string $state): string => $state === __('Freigegeben') ? 'primary' : 'warning')
                    ->icon(fn (string $state): Heroicon => $state === __('Freigegeben') ? Heroicon::OutlinedCheckCircle : Heroicon::OutlinedClock),
                TextColumn::make('questions_count')
                    ->label(__('Hausaufgabe'))
                    ->state(fn (Lesson $record): string => $record->questions_count === 0
                        ? __('Noch kein Entwurf')
                        : trans_choice(':count Frage|:count Fragen', $record->questions_count))
                    ->color(fn (Lesson $record): string => $record->questions_count === 0 ? 'gray' : 'primary'),
                TextColumn::make('submissions_count')
                    ->label(__('Abgegeben'))
                    ->state(fn (Lesson $record): ?string => $record->isPublished() && $this->teacher()->can('viewResults', $record) ? "{$record->submissions_count} / {$record->students_count}" : null)
                    ->placeholder('–'),
            ])
            ->recordUrl(fn (Lesson $record): string => LessonResource::getUrl('edit', ['record' => $record]))
            ->recordActions([
                $this->draftHomeworkAction(),
                $this->releaseAction(),
            ])
            ->emptyStateIcon(Heroicon::OutlinedSun)
            ->emptyStateHeading(__('Keine Stunde in den letzten Schultagen'))
            ->emptyStateDescription(__('Erfasse deine Stunde, die KI macht einen Entwurf für die Hausaufgabe, und du gibst ihn in 30 Sekunden frei.'))
            ->emptyStateActions([
                Action::make('create')
                    ->label(__('Stunde erfassen'))
                    ->icon(Heroicon::Plus)
                    ->url(fn (): string => LessonResource::getUrl('create')),
            ]);
    }

    private function draftHomeworkAction(): Action
    {
        return Action::make('draftHomework')
            ->label(__('Hausaufgabe mit KI erstellen'))
            ->icon(Heroicon::OutlinedSparkles)
            ->color('gray')
            ->authorize('update')
            ->hidden(fn (Lesson $record): bool => $record->isPublished())
            ->requiresConfirmation(fn (Lesson $record): bool => $record->questions_count > 0)
            ->modalHeading(__('Hausaufgabe neu erstellen?'))
            ->modalDescription(fn (Lesson $record): string => HomeworkDraft::confirmationText($record))
            ->modalSubmitActionLabel(__('Neu erstellen'))
            ->action(function (Lesson $record): void {
                try {
                    HomeworkDraft::create($record);
                } catch (Throwable $exception) {
                    report($exception);

                    Notification::make()
                        ->danger()
                        ->title(__('KI-Entwurf fehlgeschlagen'))
                        ->body(__('Deine Stunde ist gespeichert. Bitte versuche es gleich noch einmal.'))
                        ->send();

                    return;
                }

                Notification::make()
                    ->success()
                    ->title(__('Hausaufgabe erstellt'))
                    ->body(HomeworkDraft::isRealAi($record)
                        ? __('Prüfe Zusammenfassung und Fragen, bevor du die Hausaufgabe freigibst.')
                        : __('Demo-Modus – KI-Anbindung vorbereitet, aber nicht aktiv. Zusammenfassung und Fragen sind Beispielentwürfe, bitte prüfe und passe sie an.'))
                    ->persistent()
                    ->send();
            });
    }

    private function releaseAction(): Action
    {
        return Action::make('release')
            ->label(__('Freigeben'))
            ->icon(Heroicon::OutlinedPaperAirplane)
            ->authorize('publish')
            ->hidden(fn (Lesson $record): bool => $record->isPublished())
            ->disabled(fn (Lesson $record): bool => $record->questions_count === 0)
            ->tooltip(fn (Lesson $record): ?string => $record->questions_count === 0 ? __('Lege zuerst Fragen an, zum Beispiel mit der KI.') : null)
            ->modalHeading(__('Hausaufgabe freigeben'))
            ->modalDescription(fn (Lesson $record): string => __('Für die ganze Klasse :class. Die Schüler:innen finden die Fragen danach in ihrem Lernbereich. Den Erwartungshorizont sehen sie nicht.', ['class' => $record->schoolClass->name]))
            ->modalSubmitActionLabel(__('Freigeben'))
            ->schema([$this->dueOnField()])
            ->action(function (array $data, Lesson $record): void {
                $dueOn = Carbon::parse($data['due_on']);
                $reached = LessonRelease::release($record, $this->teacher(), $dueOn);

                if ($reached === 0) {
                    Notification::make()
                        ->warning()
                        ->title(__('Niemand freigegeben'))
                        ->body(__('In der Klasse :class sind noch keine Schüler:innen eingetragen.', ['class' => $record->schoolClass->name]))
                        ->send();

                    return;
                }

                Notification::make()
                    ->success()
                    ->title(__('Hausaufgabe freigegeben'))
                    ->body(__('Für :students, Abgabe bis :date.', ['students' => trans_choice(':count Schüler:in|:count Schüler:innen', $reached), 'date' => $dueOn->format('d.m.Y')]))
                    ->send();
            });
    }

    private function dueOnField(): DatePicker
    {
        return DatePicker::make('due_on')
            ->label(__('Abgabe bis'))
            ->default(today()->addDays(LessonRelease::DEFAULT_DUE_DAYS))
            ->minDate(today())
            ->required();
    }

    /**
     * Today's lessons and those of the last two school days, only the teacher's own and without Förderaufgaben (they
     * are made from a competency, not held in a lesson).
     *
     * @return Builder<Lesson>
     */
    private function lessonsQuery(): Builder
    {
        $earliest = today();

        for ($schoolDays = 0; $schoolDays < self::SCHOOL_DAYS_BACK;) {
            $earliest = $earliest->subDay();
            $schoolDays += $earliest->isWeekday() ? 1 : 0;
        }

        return Lesson::query()
            ->whereBelongsTo($this->teacher(), 'teacher')
            ->whereNull('practice_competency_id')
            ->whereDate('held_on', '>=', $earliest)
            ->whereDate('held_on', '<=', today());
    }

    /**
     * The lessons "Alle bereiten freigeben" releases: not released yet, with questions, a class with students, and the
     * teacher may publish them.
     *
     * @return Collection<int, Lesson>
     */
    private function releasableLessons(): Collection
    {
        return $this->lessonsQuery()
            ->whereNull('published_at')
            ->whereHas('questions')
            ->whereHas('schoolClass.students')
            ->with('schoolClass')
            ->orderBy('held_on')
            ->get()
            ->filter(fn (Lesson $lesson): bool => $this->teacher()->can('publish', $lesson))
            ->values();
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return [
            'lessonCount' => $this->lessonsQuery()->count(),
            'waitingCount' => $this->lessonsQuery()->whereNull('published_at')->count(),
            'readyCount' => $this->releasableLessons()->count(),
        ];
    }

    private function teacher(): User
    {
        /** @var User */
        return auth()->user();
    }
}
