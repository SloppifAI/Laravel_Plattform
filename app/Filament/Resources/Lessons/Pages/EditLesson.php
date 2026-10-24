<?php

namespace App\Filament\Resources\Lessons\Pages;

use App\Ai\LessonAssistant;
use App\Filament\Pages\RecordLesson;
use App\Filament\Resources\Lessons\LessonResource;
use App\Models\Competency;
use App\Models\Lesson;
use App\Models\LessonRecording;
use App\QuestionType;
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
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * @property-read Lesson $record
 */
class EditLesson extends EditRecord
{
    protected static string $resource = LessonResource::class;

    /**
     * Tells the relation managers to re-render after the questions or the delegation changed.
     */
    public const string HOMEWORK_CHANGED_EVENT = 'lesson-homework-changed';

    public function getSubheading(): ?string
    {
        /** @var Lesson $lesson */
        $lesson = $this->getRecord();

        if (! $lesson->isPublished()) {
            return 'Entwurf – noch nicht verteilt';
        }

        return "Verteilt an {$lesson->students()->count()} Schüler:innen · Abgabe bis {$lesson->due_on?->format('d.m.Y')}";
    }

    protected function getHeaderActions(): array
    {
        return [
            // Audio-only recording mode: reserved for the lesson's own teacher, not for the school administration.
            // Stays reachable while the school has recordings off, so existing recordings can still be heard and deleted.
            Action::make('record')
                ->label('Stunde aufnehmen')
                ->icon(Heroicon::OutlinedMicrophone)
                ->color('gray')
                ->tooltip(fn (Lesson $record): ?string => auth()->user()?->can('record', [LessonRecording::class, $record]) ? null : 'Aufnahmen sind für deine Schule nicht freigeschaltet.')
                ->visible(fn (Lesson $record): bool => auth()->user()?->can('create', [LessonRecording::class, $record]) ?? false)
                ->url(fn (Lesson $record): string => RecordLesson::getUrl(['lesson' => $record])),
            Action::make('draftHomework')
                ->label('Hausaufgabe mit KI erstellen')
                ->icon(Heroicon::OutlinedSparkles)
                ->authorize('update')
                // Once delegated, students may already be working on the questions.
                ->hidden(fn (Lesson $record): bool => $record->isPublished())
                ->requiresConfirmation(fn (Lesson $record): bool => $record->questions()->exists())
                ->modalHeading('Hausaufgabe neu erstellen?')
                ->modalDescription('Zusammenfassung und alle bisherigen Fragen werden durch einen neuen KI-Entwurf ersetzt.')
                ->modalSubmitActionLabel('Neu erstellen')
                ->action(function (Lesson $record): void {
                    // The AI works from the saved notes, so unsaved edits must not get lost.
                    $this->save(shouldRedirect: false, shouldSendSavedNotification: false);

                    try {
                        $this->replaceHomeworkWithDraft($record, app(LessonAssistant::class)->draftHomework($record));
                    } catch (Throwable $exception) {
                        report($exception);

                        Notification::make()
                            ->danger()
                            ->title('KI-Entwurf fehlgeschlagen')
                            ->body('Deine Stunde ist gespeichert. Bitte versuche es gleich noch einmal.')
                            ->send();

                        return;
                    }

                    $this->refreshFormData(['summary']);
                    $this->dispatch(self::HOMEWORK_CHANGED_EVENT);

                    Notification::make()
                        ->success()
                        ->title('Hausaufgabe erstellt')
                        ->body(config('services.anthropic.enabled')
                            ? 'Prüfe Zusammenfassung und Fragen, bevor du die Hausaufgabe verteilst.'
                            : 'Demo-Modus – KI-Anbindung vorbereitet, aber nicht aktiv. Zusammenfassung und Fragen sind Beispielentwürfe, bitte prüfe und passe sie an.')
                        ->persistent()
                        ->send();
                }),
            Action::make('delegate')
                ->label('Hausaufgabe verteilen')
                ->icon(Heroicon::OutlinedPaperAirplane)
                ->authorize('update')
                ->hidden(fn (Lesson $record): bool => $record->isPublished())
                ->disabled(fn (Lesson $record): bool => $record->questions()->doesntExist())
                ->tooltip(fn (Lesson $record): ?string => $record->questions()->doesntExist() ? 'Lege zuerst Fragen an, zum Beispiel mit der KI.' : null)
                ->modalDescription('Die Schüler:innen finden die Fragen danach in ihrem Lernbereich. Den Erwartungshorizont sehen sie nicht.')
                ->modalSubmitActionLabel('Verteilen')
                ->schema([
                    DatePicker::make('due_on')
                        ->label('Abgabe bis')
                        ->default(today()->addDays(7))
                        ->minDate(today())
                        ->required(),
                    Radio::make('audience')
                        ->label('Für wen?')
                        ->options(fn (Lesson $record): array => [
                            'class' => "Ganze Klasse {$record->schoolClass->name}",
                            'selected' => 'Ausgewählte Schüler:innen',
                        ])
                        ->default('class')
                        ->required()
                        ->live(),
                    CheckboxList::make('students')
                        ->label('Schüler:innen')
                        ->options(fn (Lesson $record): array => $record->schoolClass->students()->orderBy('name')->pluck('name', 'id')->all())
                        ->visible(fn (Get $get): bool => $get('audience') === 'selected')
                        ->required()
                        ->bulkToggleable()
                        ->columns(2),
                ])
                ->action(function (array $data, Lesson $record): void {
                    $this->save(shouldRedirect: false, shouldSendSavedNotification: false);

                    $classStudentIds = $record->schoolClass->students()->pluck('id');
                    $studentIds = $data['audience'] === 'class'
                        ? $classStudentIds
                        : $classStudentIds->intersect($data['students'] ?? []);

                    if ($studentIds->isEmpty()) {
                        Notification::make()
                            ->warning()
                            ->title('Niemand ausgewählt')
                            ->body('In der Klasse sind noch keine Schüler:innen eingetragen.')
                            ->send();

                        return;
                    }

                    DB::transaction(function () use ($record, $data, $studentIds): void {
                        $record->update(['published_at' => now(), 'due_on' => $data['due_on']]);
                        $record->students()->sync($studentIds->all());
                    });

                    $this->dispatch(self::HOMEWORK_CHANGED_EVENT);

                    Notification::make()
                        ->success()
                        ->title('Hausaufgabe verteilt')
                        ->body("An {$studentIds->count()} Schüler:innen, Abgabe bis ".Carbon::parse($data['due_on'])->format('d.m.Y').'.')
                        ->send();
                }),
            Action::make('withdraw')
                ->label('Verteilung zurückziehen')
                ->icon(Heroicon::OutlinedArrowUturnLeft)
                ->color('gray')
                ->authorize('update')
                ->visible(fn (Lesson $record): bool => $record->isPublished() && $record->submissions()->doesntExist())
                ->requiresConfirmation()
                ->modalDescription('Die Hausaufgabe verschwindet wieder aus dem Lernbereich der Schüler:innen. Das geht nur, solange noch niemand abgegeben hat.')
                ->modalSubmitActionLabel('Zurückziehen')
                ->action(function (Lesson $record): void {
                    DB::transaction(function () use ($record): void {
                        $record->update(['published_at' => null, 'due_on' => null]);
                        $record->students()->detach();
                    });

                    $this->dispatch(self::HOMEWORK_CHANGED_EVENT);

                    Notification::make()
                        ->success()
                        ->title('Verteilung zurückgezogen')
                        ->send();
                }),
            DeleteAction::make(),
        ];
    }

    /**
     * Stores the AI draft: the summary plus the questions, replacing the previous ones in the given order.
     *
     * @param  array{summary: string, questions: list<array{type: QuestionType, prompt: string, expected_answer: string, competency_id: int|null}>}  $draft
     */
    private function replaceHomeworkWithDraft(Lesson $lesson, array $draft): void
    {
        // AI output is untrusted: an unknown competency would break the foreign key, so it is dropped instead.
        $allowedCompetencyIds = Competency::forSubject($lesson->subject)->pluck('id');

        DB::transaction(function () use ($lesson, $draft, $allowedCompetencyIds): void {
            $lesson->update(['summary' => $draft['summary']]);
            $lesson->questions()->delete();
            $lesson->questions()->createMany(collect($draft['questions'])->values()->map(fn (array $question, int $index): array => [
                'type' => $question['type'],
                'prompt' => $question['prompt'],
                'expected_answer' => $question['expected_answer'],
                'competency_id' => $allowedCompetencyIds->contains($question['competency_id'] ?? null) ? $question['competency_id'] : null,
                'position' => $index + 1,
            ])->all());
        });
    }
}
