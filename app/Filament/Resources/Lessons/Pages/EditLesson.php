<?php

namespace App\Filament\Resources\Lessons\Pages;

use App\Filament\Pages\ClassBook;
use App\Filament\Pages\LessonStarter;
use App\Filament\Pages\RecordLesson;
use App\Filament\Resources\Lessons\LessonResource;
use App\Models\ClassBookEntry;
use App\Models\Lesson;
use App\Models\LessonRecording;
use App\Models\SchoolClass;
use App\Support\ClassBookDraft;
use App\Support\HomeworkDraft;
use App\Support\LessonRelease;
use App\Support\RecordingConsentGate;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Radio;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\On;
use Throwable;

/**
 * @property-read Lesson $record
 */
class EditLesson extends EditRecord
{
    protected static string $resource = LessonResource::class;

    /**
     * Tells the relation managers to re-render after the questions or the release changed.
     */
    public const string HOMEWORK_CHANGED_EVENT = 'lesson-homework-changed';

    /**
     * Sent by the questions table's own "Hausaufgabe mit KI erstellen" button: this page runs the step, so unsaved notes are saved first.
     */
    public const string OPEN_HOMEWORK_DRAFT_EVENT = 'lesson-open-homework-draft';

    #[On(self::OPEN_HOMEWORK_DRAFT_EVENT)]
    public function openHomeworkDraft(): void
    {
        $this->mountAction('draftHomework');
    }

    public function getSubheading(): ?string
    {
        /** @var Lesson $lesson */
        $lesson = $this->getRecord();

        if (! $lesson->isPublished()) {
            return __('Entwurf – wartet auf Freigabe');
        }

        return __('Freigegeben für :students · Abgabe bis :date', [
            'students' => trans_choice(':count Schüler:in|:count Schüler:innen', $lesson->students()->count()),
            'date' => $lesson->due_on?->format('d.m.Y'),
        ]);
    }

    /**
     * Moving the lesson to another class needs the same right as creating one there. A lesson that stays in its class
     * is not checked, so drafts of a class the teacher no longer teaches stay editable. The class select only offers
     * the allowed classes; this is the backstop for a forged form state. A lesson the students have used stays in its
     * class (LessonPolicy::changeClass): the select is disabled then and not part of $data, a forged value is refused here.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (isset($data['school_class_id']) && (int) $data['school_class_id'] !== (int) $this->record->getOriginal('school_class_id')) {
            Gate::authorize('changeClass', $this->record);
            Gate::authorize('createFor', [Lesson::class, SchoolClass::findOrFail($data['school_class_id'])]);
        }

        return $data;
    }

    protected function getHeaderActions(): array
    {
        return [
            // The board for the projector: only for the lesson's own teacher, a Förderaufgabe has no class-wide results (LessonStarter 404s),
            // and not for a substitute whose period is over (the board shows what the class got wrong).
            Action::make('starter')
                ->label(__('Einstieg nächste Stunde'))
                ->icon(Heroicon::OutlinedPresentationChartBar)
                ->color('gray')
                ->visible(fn (Lesson $record): bool => ! $record->isPracticeTask() && $record->teacher()->is(auth()->user()) && auth()->user()->can('viewResults', $record))
                ->url(fn (Lesson $record): string => LessonStarter::getUrl(['lesson' => $record])),
            // The Klassenbuch entry of this lesson: opens the existing one or starts the draft, then the book shows it.
            Action::make('classBook')
                ->label(__('Klassenbuch-Eintrag'))
                ->icon(Heroicon::OutlinedBookOpen)
                ->color('gray')
                // A Förderaufgabe is made from a competency and not held in the class, so the book has no entry for it.
                ->visible(fn (Lesson $record): bool => ! $record->isPracticeTask() && Gate::allows('createFromLesson', [ClassBookEntry::class, $record]))
                ->action(function (Lesson $record): void {
                    Gate::authorize('createFromLesson', [ClassBookEntry::class, $record]);

                    $entry = $record->classBookEntry ?? ClassBookDraft::createFor($record);

                    // An open entry goes straight into its edit dialog; a confirmed one is locked, the book just lists it.
                    $this->redirect(ClassBook::getUrl([
                        'klasse' => $entry->school_class_id,
                        ...(Gate::allows('update', $entry) ? ['tableAction' => 'edit', 'tableActionRecord' => $entry->getKey()] : []),
                    ]));
                }),
            // Tuto-Mikro: reserved for the lesson's own teacher, not for the school administration.
            // Stays reachable while the school has Tuto-Mikro off, so existing notes can still be read and deleted.
            // Hidden where the page would refuse (Förderaufgabe, a subject the school keeps free of the microphone).
            Action::make('record')
                ->label('Tuto-Mikro')
                ->icon(Heroicon::OutlinedSignal)
                ->color('gray')
                ->tooltip(fn (Lesson $record): ?string => auth()->user()?->can('record', [LessonRecording::class, $record]) ? null : __('Tuto-Mikro ist für deine Schule nicht freigeschaltet.'))
                // A Förderaufgabe has no lesson notes to take over, and the next step (drafting from the notes) is hidden there as well.
                ->visible(fn (Lesson $record): bool => RecordingConsentGate::lessonRefusal($record) === null && (auth()->user()?->can('create', [LessonRecording::class, $record]) ?? false))
                ->url(fn (Lesson $record): string => RecordLesson::getUrl(['lesson' => $record])),
            Action::make('draftHomework')
                ->label(__('Hausaufgabe mit KI erstellen'))
                ->icon(Heroicon::OutlinedSparkles)
                ->authorize('update')
                // Once released, students may already be working on the questions; a Förderaufgabe is drafted from its competency, not from lesson notes.
                ->hidden(fn (Lesson $record): bool => $record->isPublished() || $record->isPracticeTask())
                ->requiresConfirmation(fn (Lesson $record): bool => $record->questions()->exists())
                ->modalHeading(__('Hausaufgabe neu erstellen?'))
                ->modalDescription(fn (Lesson $record): string => HomeworkDraft::confirmationText($record))
                ->modalSubmitActionLabel(__('Neu erstellen'))
                ->action(function (Lesson $record): void {
                    // The AI works from the saved notes, so unsaved edits must not get lost.
                    $this->save(shouldRedirect: false, shouldSendSavedNotification: false);

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

                    $this->refreshFormData(['summary']);
                    $this->dispatch(self::HOMEWORK_CHANGED_EVENT);

                    Notification::make()
                        ->success()
                        ->title(__('Hausaufgabe erstellt'))
                        ->body(HomeworkDraft::isRealAi($record)
                            ? __('Prüfe Zusammenfassung und Fragen, bevor du die Hausaufgabe freigibst.')
                            : __('Demo-Modus – KI-Anbindung vorbereitet, aber nicht aktiv. Zusammenfassung und Fragen sind Beispielentwürfe, bitte prüfe und passe sie an.'))
                        ->persistent()
                        ->send();
                }),
            Action::make('delegate')
                ->label(__('Freigeben'))
                ->icon(Heroicon::OutlinedPaperAirplane)
                ->authorize('publish')
                ->hidden(fn (Lesson $record): bool => $record->isPublished())
                ->disabled(fn (Lesson $record): bool => $record->questions()->doesntExist())
                ->tooltip(fn (Lesson $record): ?string => $record->questions()->doesntExist() ? __('Lege zuerst Fragen an, zum Beispiel mit der KI.') : null)
                ->modalHeading(__('Hausaufgabe freigeben'))
                ->modalDescription(__('Die Schüler:innen finden die Fragen danach in ihrem Lernbereich. Den Erwartungshorizont sehen sie nicht.'))
                ->modalSubmitActionLabel(__('Freigeben'))
                ->schema([
                    DatePicker::make('due_on')
                        ->label(__('Abgabe bis'))
                        ->default(today()->addDays(7))
                        ->minDate(today())
                        ->required(),
                    Radio::make('audience')
                        ->label(__('Für wen?'))
                        ->options(fn (Lesson $record): array => [
                            'class' => __('Ganze Klasse :class', ['class' => $record->schoolClass->name]),
                            'selected' => __('Ausgewählte Schüler:innen'),
                        ])
                        // A Förderaufgabe was made for named students, who are already attached to the draft.
                        ->default(fn (Lesson $record): string => $record->isPracticeTask() ? 'selected' : 'class')
                        ->required()
                        ->live(),
                    CheckboxList::make('students')
                        ->label(__('Schüler:innen'))
                        ->options(fn (Lesson $record): array => $record->schoolClass->students()->orderBy('name')->pluck('name', 'id')->all())
                        // Only those still in the class: the list offers no one else, so a student who moved away would fail validation unseen.
                        ->default(fn (Lesson $record): array => $record->students()->where('users.school_class_id', $record->school_class_id)->pluck('users.id')->all())
                        ->visible(fn (Get $get): bool => $get('audience') === 'selected')
                        ->required()
                        ->bulkToggleable()
                        ->columns(2),
                ])
                ->action(function (array $data, Lesson $record): void {
                    $this->save(shouldRedirect: false, shouldSendSavedNotification: false);

                    $dueOn = Carbon::parse($data['due_on']);
                    $reached = LessonRelease::release($record, auth()->user(), $dueOn, $data['audience'] === 'class' ? null : ($data['students'] ?? []));

                    if ($reached === 0) {
                        Notification::make()
                            ->warning()
                            ->title(__('Niemand ausgewählt'))
                            ->body(__('In der Klasse sind noch keine Schüler:innen eingetragen.'))
                            ->send();

                        return;
                    }

                    $this->dispatch(self::HOMEWORK_CHANGED_EVENT);

                    Notification::make()
                        ->success()
                        ->title(__('Hausaufgabe freigegeben'))
                        ->body(__('Für :students, Abgabe bis :date.', [
                            'students' => trans_choice(':count Schüler:in|:count Schüler:innen', $reached),
                            'date' => $dueOn->format('d.m.Y'),
                        ]))
                        ->send();
                }),
            // Pupils who joined the class after the release (class code, moved in by the administration) never got the homework;
            // the teacher picks them here, as some may have been left out on purpose. Only offered while there is someone to add.
            Action::make('addStudents')
                ->label(__('Weitere Schüler:innen hinzufügen'))
                ->icon(Heroicon::OutlinedUserPlus)
                ->color('gray')
                ->authorize('publish')
                ->visible(fn (Lesson $record): bool => $record->isPublished() && ! $record->isPracticeTask() && LessonRelease::studentsWithoutHomework($record)->exists())
                ->modalHeading(__('Weitere Schüler:innen hinzufügen'))
                ->modalDescription(__('Diese Schüler:innen haben die Hausaufgabe noch nicht bekommen, zum Beispiel weil sie erst nach der Freigabe in die Klasse gekommen sind. Sie bekommen sie mit der bisherigen Abgabefrist.'))
                ->modalSubmitActionLabel(__('Hinzufügen'))
                ->schema([
                    CheckboxList::make('students')
                        ->label(__('Schüler:innen'))
                        ->options(fn (Lesson $record): array => LessonRelease::studentsWithoutHomework($record)->orderBy('name')->pluck('name', 'id')->all())
                        ->required()
                        ->bulkToggleable()
                        ->columns(2),
                ])
                ->action(function (array $data, Lesson $record): void {
                    $reached = LessonRelease::addStudents($record, auth()->user(), $data['students']);

                    $this->dispatch(self::HOMEWORK_CHANGED_EVENT);

                    Notification::make()
                        ->success()
                        ->title(__('Schüler:innen hinzugefügt'))
                        ->body(__('Für :students, Abgabe bis :date.', [
                            'students' => trans_choice(':count Schüler:in|:count Schüler:innen', $reached),
                            'date' => $record->due_on?->format('d.m.Y'),
                        ]))
                        ->send();
                }),
            Action::make('withdraw')
                ->label(__('Freigabe zurücknehmen'))
                ->icon(Heroicon::OutlinedArrowUturnLeft)
                ->color('gray')
                ->authorize('update')
                ->visible(fn (Lesson $record): bool => $record->isPublished() && $record->submissions()->doesntExist())
                ->requiresConfirmation()
                ->modalDescription(__('Die Hausaufgabe verschwindet wieder aus dem Lernbereich der Schüler:innen und wartet wieder auf Freigabe. Das geht nur, solange noch niemand abgegeben hat.'))
                ->modalSubmitActionLabel(__('Zurücknehmen'))
                ->action(function (Lesson $record): void {
                    LessonRelease::withdraw($record);

                    $this->dispatch(self::HOMEWORK_CHANGED_EVENT);

                    Notification::make()
                        ->success()
                        ->title(__('Freigabe zurückgenommen'))
                        ->send();
                }),
            DeleteAction::make(),
        ];
    }
}
