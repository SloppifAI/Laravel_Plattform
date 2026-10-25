<?php

namespace App\Filament\Resources\Lessons\Schemas;

use App\Filament\Resources\Lessons\Pages\EditLesson;
use App\Models\Competency;
use App\Models\Lesson;
use App\Models\SchoolClass;
use App\Models\TimetableEntry;
use App\Models\User;
use App\Support\HomeworkDraft;
use App\Support\LessonReview;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Throwable;

class LessonForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('Unterrichtsstunde'))
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        // Only a help for entering: it fills in class and subject from the teacher's timetable and is not saved.
                        Select::make('timetable_entry_id')
                            ->label(__('Laut Stundenplan'))
                            ->options(fn (Get $get): array => static::timetableSlots(auth()->user(), $get('held_on'))
                                ->mapWithKeys(fn (TimetableEntry $entry): array => [$entry->getKey() => collect([
                                    TimetableEntry::periodLabel($entry->period),
                                    $entry->schoolClass->name,
                                    $entry->subject,
                                ])->implode(' · ')])
                                ->all())
                            ->default(fn (): ?int => static::currentSlot()?->getKey())
                            ->placeholder(__('Stunde außerhalb des Stundenplans'))
                            ->helperText(__('Übernimmt Klasse und Fach aus deinem Stundenplan. Du kannst beides danach noch ändern.'))
                            ->live()
                            ->afterStateUpdated(function (mixed $state, Get $get, Set $set): void {
                                // Only an offered slot fills anything in; the state is Livewire input until the form validates it.
                                $entry = filled($state) ? static::timetableSlots(auth()->user(), $get('held_on'))->find($state) : null;

                                if ($entry !== null) {
                                    $set('school_class_id', $entry->school_class_id);
                                    $set('subject', $entry->subject);
                                }
                            })
                            ->dehydrated(false)
                            ->visible(fn (Get $get, string $operation): bool => $operation === 'create' && static::timetableSlots(auth()->user(), $get('held_on'))->isNotEmpty())
                            ->columnSpanFull(),
                        Select::make('school_class_id')
                            ->label(__('Klasse'))
                            // Admins: the classes of their school. Teachers: the classes they teach (see LessonPolicy::createFor) plus the
                            // class an existing lesson already sits in, so it stays editable after the assignment ended. Also on the
                            // server: a forged id fails validation.
                            ->relationship('schoolClass', 'name', fn (Builder $query, ?Lesson $record): Builder => static::placeableClasses($query, auth()->user(), $record))
                            ->searchable()
                            ->preload()
                            ->live()
                            // Released or used by students, the lesson's students and results belong to its class (LessonPolicy::changeClass). Not saved while disabled.
                            ->disabled(fn (?Lesson $record): bool => $record !== null && auth()->user()->cannot('changeClass', $record))
                            ->helperText(fn (Select $component): ?string => $component->isDisabled() ? __('Die Schüler:innen der Klasse haben diese Stunde schon bekommen, deshalb bleibt sie in ihrer Klasse.') : null)
                            ->default(fn (): ?int => static::currentSlot()?->school_class_id)
                            ->required(),
                        DatePicker::make('held_on')
                            ->label(__('Gehalten am'))
                            ->default(today())
                            ->live()
                            // The slots of another weekday are others; the chosen one no longer applies.
                            ->afterStateUpdated(fn (Set $set) => $set('timetable_entry_id', null))
                            ->required(),
                        TextInput::make('subject')
                            ->label(__('Fach'))
                            ->default(fn (): ?string => static::currentSlot()?->subject)
                            // Suggesting the framework's subjects keeps the competency mapping working.
                            ->datalist(fn (): array => Competency::query()->whereNotNull('subject')->distinct()->orderBy('subject')->pluck('subject')->all())
                            ->live(onBlur: true)
                            ->required()
                            ->maxLength(255),
                        TextInput::make('topic')
                            ->label(__('Thema'))
                            ->required()
                            ->maxLength(255),
                    ]),
                View::make('filament.resources.lessons.follow-up-panel')
                    ->columnSpanFull()
                    ->visible(fn (Get $get, ?Lesson $record): bool => static::previousLessonReview($get, $record) !== null)
                    ->viewData(fn (Get $get, ?Lesson $record): array => static::previousLessonReview($get, $record) ?? []),
                Section::make(__('Inhalt der Stunde'))
                    ->description(__('Daraus erstellt die KI Zusammenfassung und Hausaufgabe. Bitte keine personenbezogenen Daten eintragen.'))
                    ->columnSpanFull()
                    ->schema([
                        Textarea::make('notes')
                            ->label(__('Notizen'))
                            ->helperText(__('Stichpunkte, Tafelbild oder Notizen aus der Stunde'))
                            ->rows(12)
                            ->required(),
                        FileUpload::make('attachments')
                            ->label(__('Arbeitsblätter'))
                            ->helperText(__('Fotos oder Scans analoger Arbeitsblätter (JPG, PNG, WebP, HEIC oder PDF, max. 10 MB je Datei)'))
                            ->multiple()
                            ->disk('local')
                            ->directory('worksheets')
                            // Only formats the AI reads, so no worksheet is silently left out of the draft.
                            ->acceptedFileTypes([...Lesson::WORKSHEET_IMAGE_TYPES, 'application/pdf'])
                            // Browsers outside Apple's report HEIC photos without a type.
                            ->mimeTypeMap(['heic' => 'image/heic', 'heif' => 'image/heif'])
                            ->maxSize(10240)
                            ->openable()
                            ->downloadable()
                            // The local disk holds every teacher's worksheets, so only this lesson's paths may be submitted.
                            ->preventFilePathTampering(),
                    ]),
                // From the first save on: a summary in the database is what the students read and what a new AI draft keeps.
                Section::make(__('Zusammenfassung'))
                    ->key('summary-section')
                    ->description(fn (?Lesson $record): string => match (true) {
                        blank($record?->summary) => __('Noch leer. „Hausaufgabe mit KI erstellen“ schreibt sie aus deinen Notizen, oder du schreibst sie selbst. Die Schüler:innen lesen sie vor den Fragen.'),
                        (bool) $record->summary_from_app => __('Von einer App gesetzt. Ein neuer KI-Entwurf behält sie und erstellt nur die Fragen. Die Schüler:innen lesen sie vor den Fragen, du kannst sie vor der Freigabe anpassen.'),
                        default => __('Die Schüler:innen lesen sie vor den Fragen, du kannst sie vor der Freigabe anpassen. Ein neuer KI-Entwurf behält sie und erstellt nur die Fragen.'),
                    })
                    ->columnSpanFull()
                    ->visible(fn (?Lesson $record): bool => $record !== null)
                    ->afterHeader([
                        Action::make('reviseSummary')
                            ->label(__('Zusammenfassung neu erstellen'))
                            ->icon(Heroicon::OutlinedSparkles)
                            ->color('gray')
                            ->authorize('update')
                            // Like the draft: not once students may be reading it, and a Förderaufgabe has no notes to build on.
                            ->visible(fn (?Lesson $record): bool => filled($record?->summary) && ! $record->isPublished() && ! $record->isPracticeTask())
                            ->requiresConfirmation()
                            ->modalHeading(__('Zusammenfassung neu erstellen?'))
                            ->modalDescription(__('Die KI schreibt die Zusammenfassung aus deinen Notizen und Arbeitsblättern neu und baut dabei auf der bisherigen auf: Was darin steht, bleibt, und wird ergänzt oder berichtigt.'))
                            ->modalSubmitActionLabel(__('Neu erstellen'))
                            ->action(function (Lesson $record, EditLesson $livewire): void {
                                // The AI works from the saved notes and the saved summary, so unsaved edits must not get lost.
                                $livewire->save(shouldRedirect: false, shouldSendSavedNotification: false);

                                try {
                                    HomeworkDraft::reviseSummary($record->refresh());
                                } catch (Throwable $exception) {
                                    report($exception);

                                    Notification::make()
                                        ->danger()
                                        ->title(__('KI-Entwurf fehlgeschlagen'))
                                        ->body(__('Deine Stunde ist gespeichert. Bitte versuche es gleich noch einmal.'))
                                        ->send();

                                    return;
                                }

                                $livewire->refreshFormData(['summary']);

                                Notification::make()
                                    ->success()
                                    ->title(__('Zusammenfassung neu erstellt'))
                                    ->body(__('Prüfe sie, bevor du die Hausaufgabe freigibst.'))
                                    ->send();
                            }),
                    ])
                    ->schema([
                        // No file attachments: the summary is text for the class, worksheets belong to "Inhalt der Stunde".
                        RichEditor::make('summary')
                            ->hiddenLabel()
                            ->toolbarButtons([
                                ['bold', 'italic', 'underline'],
                                ['h2', 'h3'],
                                ['bulletList', 'orderedList'],
                                ['undo', 'redo'],
                            ])
                            ->fileAttachments(false),
                    ]),
            ]);
    }

    /**
     * The teacher's timetable slots on the weekday of the given date, only in classes they may enter a lesson for
     * (a timetable entry grants nothing by itself). Administrations have no slots of their own.
     *
     * @return Collection<int, TimetableEntry>
     */
    private static function timetableSlots(User $user, mixed $heldOn): Collection
    {
        $day = rescue(fn (): ?Carbon => filled($heldOn) ? Carbon::parse($heldOn) : null, report: false);

        if ($day === null || ! $user->isTeacher()) {
            return new Collection;
        }

        return TimetableEntry::query()
            ->taughtOn($user, $day)
            ->whereIn('school_class_id', SchoolClass::query()->taughtBy($user)->select('school_classes.id'))
            ->with('schoolClass')
            ->orderBy('period')
            ->get();
    }

    /**
     * The slot a lesson entered now most likely belongs to: the one running, otherwise the last one of today that has
     * already begun (TimetableEntry::periodAt()). Preselected on a new lesson; null if there is none.
     */
    private static function currentSlot(): ?TimetableEntry
    {
        $now = TimetableEntry::schoolNow();
        $period = TimetableEntry::periodAt($now);

        if ($period === null) {
            return null;
        }

        return static::timetableSlots(auth()->user(), $now)
            ->filter(fn (TimetableEntry $entry): bool => $entry->period <= $period)
            ->last();
    }

    /**
     * @param  Builder<SchoolClass>  $query
     * @return Builder<SchoolClass>
     */
    private static function placeableClasses(Builder $query, User $user, ?Lesson $record): Builder
    {
        return $query
            ->where('school_classes.school_id', $user->school_id)
            ->unless($user->isAdmin(), fn (Builder $query): Builder => $query->where(fn (Builder $query) => $query
                ->taughtBy($user)
                ->when($record !== null, fn (Builder $query) => $query->orWhere('school_classes.id', $record->school_class_id))));
    }

    /**
     * What the class had not understood in the previous lesson of the chosen subject, to repeat at the start of this
     * one. Only lessons the acting user may review count (LessonReview checks that) and whose results they may read
     * (LessonPolicy::viewResults, like the starter board this panel links to); the lesson being edited is no "previous"
     * lesson of itself.
     *
     * @return array{previous: Lesson, points: list<array<string, mixed>>}|null
     */
    private static function previousLessonReview(Get $get, ?Lesson $record): ?array
    {
        $user = auth()->user();
        $subject = trim((string) $get('subject'));
        $heldOn = rescue(fn (): ?Carbon => filled($get('held_on')) ? Carbon::parse($get('held_on')) : null, report: false);
        $class = filled($get('school_class_id'))
            ? SchoolClass::query()->where('school_id', $user->school_id)->whereKey($get('school_class_id'))->first()
            : null;

        if ($class === null || $subject === '' || $heldOn === null) {
            return null;
        }

        $previous = LessonReview::previousLessonFor($class, $subject, $heldOn, $user);

        if ($previous === null || $previous->is($record) || $user->cannot('viewResults', $previous)) {
            return null;
        }

        $points = LessonReview::forLesson($previous)['points'];

        return $points === [] ? null : ['previous' => $previous, 'points' => $points];
    }
}
