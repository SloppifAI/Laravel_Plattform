<?php

namespace App\Filament\Resources\Lessons\RelationManagers;

use App\Ai\LessonAssistant;
use App\Filament\Resources\Lessons\Pages\EditLesson;
use App\Models\Competency;
use App\Models\Lesson;
use App\Models\Question;
use App\QuestionLevel;
use App\QuestionType;
use App\Support\HomeworkDraft;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\On;
use Throwable;

#[On(EditLesson::HOMEWORK_CHANGED_EVENT)]
class QuestionsRelationManager extends RelationManager
{
    protected static string $relationship = 'questions';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Hausaufgabe');
    }

    /**
     * Once released, students may already be answering, and deleting a question would delete their answers.
     */
    public function isReadOnly(): bool
    {
        return parent::isReadOnly() || $this->getLesson()->isPublished();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('type')
                    ->label(__('Art'))
                    ->options(QuestionType::class)
                    ->default(QuestionType::Comprehension)
                    ->required(),
                Select::make('competency_id')
                    ->label(__('Kompetenz'))
                    ->relationship(
                        'competency',
                        'name',
                        modifyQueryUsing: fn (Builder $query): Builder => $query->forSubject($this->getLesson()->subject)->orderBy('area')->orderBy('name'),
                    )
                    ->getOptionLabelFromRecordUsing(fn (Competency $record): string => "{$record->area}: {$record->name}")
                    ->searchable(['area', 'name'])
                    ->preload(),
                Textarea::make('prompt')
                    ->label(__('Frage'))
                    ->rows(3)
                    ->required()
                    ->columnSpanFull(),
                Textarea::make('prompt_basis')
                    ->label(__('Stufe Einstieg (optional)'))
                    ->helperText(__('Eine leichtere Fassung der Frage mit einem Hinweis. Schüler:innen können sie statt der Standard-Frage wählen.'))
                    ->rows(3)
                    ->columnSpanFull(),
                Textarea::make('prompt_plus')
                    ->label(__('Stufe Profi (optional)'))
                    ->helperText(__('Eine anspruchsvollere Fassung, die zum Beispiel eine Begründung verlangt. Gemessen wird an demselben Erwartungshorizont.'))
                    ->rows(3)
                    ->columnSpanFull(),
                Textarea::make('expected_answer')
                    ->label(__('Erwartungshorizont'))
                    ->helperText(__('Daran misst die KI die Antworten der Schüler:innen. Die Schüler:innen sehen ihn erst beim Wiederholen, wenn ihre Abgabe geprüft und die Frist vorbei ist.'))
                    ->rows(4)
                    ->required()
                    ->columnSpanFull(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('prompt')
            ->modelLabel(__('Frage'))
            ->pluralModelLabel(__('Fragen'))
            ->description(fn (): ?string => $this->isReadOnly()
                ? __('Die Hausaufgabe ist freigegeben. Um Fragen zu ändern, nimm die Freigabe zurück (nur möglich, solange niemand abgegeben hat).')
                : null)
            ->reorderable('position')
            ->columns([
                TextColumn::make('position')
                    ->label(__('Nr.')),
                TextColumn::make('type')
                    ->label(__('Art'))
                    ->badge(),
                TextColumn::make('prompt')
                    ->label(__('Frage'))
                    ->wrap(),
                TextColumn::make('levels')
                    ->label(__('Stufen'))
                    ->state(fn (Question $record): ?string => $record->hasLevels()
                        ? collect($record->availableLevels())->map(fn (QuestionLevel $level): string => $level->getLabel())->implode(' · ')
                        : null)
                    ->placeholder('–'),
                TextColumn::make('competency.name')
                    ->label(__('Kompetenz'))
                    ->placeholder('–')
                    ->wrap(),
                TextColumn::make('origin')
                    ->label(__('Herkunft'))
                    ->state(fn (Question $record): string => $record->ai_drafted ? __('KI-Entwurf') : __('Eigene Frage'))
                    ->badge()
                    ->color(fn (Question $record): string => $record->ai_drafted ? 'gray' : 'primary'),
            ])
            ->headerActions([
                // The page's button once more, next to the questions it drafts; the page runs it (see EditLesson::openHomeworkDraft()).
                Action::make('draftHomework')
                    ->label(__('Hausaufgabe mit KI erstellen'))
                    ->icon(Heroicon::OutlinedSparkles)
                    ->authorize(fn (): bool => Gate::allows('update', $this->getLesson()))
                    ->visible(fn (): bool => $this->pageClass === EditLesson::class && ! $this->isReadOnly() && ! $this->getLesson()->isPracticeTask())
                    ->disabled(fn (): bool => ! HomeworkDraft::hasMaterial($this->getLesson()))
                    ->tooltip(fn (): ?string => HomeworkDraft::hasMaterial($this->getLesson()) ? null : HomeworkDraft::missingMaterialHint())
                    ->action(fn () => $this->dispatch(EditLesson::OPEN_HOMEWORK_DRAFT_EVENT)),
                CreateAction::make()
                    ->mutateDataUsing(fn (array $data): array => [
                        ...$data,
                        'position' => ($this->getLesson()->questions()->max('position') ?? 0) + 1,
                    ]),
                Action::make('differentiateAll')
                    ->label(__('Stufen mit KI erstellen'))
                    ->icon(Heroicon::OutlinedSparkles)
                    ->color('gray')
                    ->authorize(fn (): bool => Gate::allows('update', $this->getLesson()))
                    ->visible(fn (): bool => ! $this->isReadOnly())
                    ->disabled(fn (): bool => $this->getLesson()->questions()->doesntExist())
                    ->tooltip(fn (): ?string => $this->getLesson()->questions()->doesntExist() ? __('Lege zuerst Fragen an, zum Beispiel mit der KI.') : null)
                    ->requiresConfirmation()
                    ->modalHeading(__('Stufen für alle Fragen erstellen?'))
                    ->modalDescription(__('Die KI ergänzt bei jeder Frage die fehlenden Stufen Einstieg und Profi. Stufen, die du schon geschrieben hast, bleiben unverändert.'))
                    ->modalSubmitActionLabel(__('Stufen erstellen'))
                    ->action(fn () => $this->createLevels($this->getLesson()->questions()->get(), replaceExisting: false)),
            ])
            ->recordActions([
                Action::make('differentiate')
                    ->label(__('Stufen mit KI erstellen'))
                    ->icon(Heroicon::OutlinedSparkles)
                    ->color('gray')
                    ->authorize('update')
                    ->visible(fn (): bool => ! $this->isReadOnly())
                    ->requiresConfirmation(fn (Question $record): bool => $record->hasLevels())
                    ->modalHeading(__('Stufen neu erstellen?'))
                    ->modalDescription(__('Die Stufen dieser Frage werden durch einen neuen KI-Entwurf ersetzt.'))
                    ->modalSubmitActionLabel(__('Neu erstellen'))
                    ->action(fn (Question $record) => $this->createLevels(collect([$record]), replaceExisting: true)),
                ViewAction::make()
                    ->hidden(fn (): bool => ! $this->isReadOnly()),
                // A question the teacher changed is theirs from now on, so a new AI draft keeps it. Only this dialog counts:
                // reordering and the AI levels leave an AI question an AI question.
                EditAction::make()
                    ->using(function (Question $record, array $data): Question {
                        $record->fill($data);
                        $record->ai_drafted = $record->ai_drafted && $record->isClean();
                        $record->save();

                        return $record;
                    }),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    /**
     * Asks the assistant for the Einstieg and Profi level of each question and stores them for the teacher to review.
     * Existing levels are only replaced when asked for; a failure keeps what was stored so far.
     *
     * @param  Collection<int, Question>  $questions
     */
    private function createLevels(Collection $questions, bool $replaceExisting): void
    {
        // A custom action authorizes itself; released homework may already be in the students' hands.
        Gate::authorize('update', $this->getLesson());
        abort_if($this->isReadOnly(), 403);

        $done = 0;

        try {
            foreach ($questions as $question) {
                if (! $replaceExisting && filled($question->prompt_basis) && filled($question->prompt_plus)) {
                    continue;
                }

                $levels = app(LessonAssistant::class)->differentiateQuestion($question);

                $question->update([
                    'prompt_basis' => $replaceExisting || blank($question->prompt_basis) ? $levels['basis'] : $question->prompt_basis,
                    'prompt_plus' => $replaceExisting || blank($question->prompt_plus) ? $levels['plus'] : $question->prompt_plus,
                ]);
                $done++;
            }
        } catch (Throwable $exception) {
            report($exception);

            Notification::make()
                ->danger()
                ->title(__('Stufen konnten nicht erstellt werden'))
                ->body(($done > 0 ? trans_choice('Bei :count Frage sind die Stufen gespeichert.|Bei :count Fragen sind die Stufen gespeichert.', $done).' ' : '').__('Bitte versuche es gleich noch einmal.'))
                ->send();

            return;
        }

        Notification::make()
            ->success()
            ->title($done === 0 ? __('Alle Fragen haben schon beide Stufen') : trans_choice('Stufen für :count Frage erstellt|Stufen für :count Fragen erstellt', $done))
            ->body($done === 0 ? null : ($this->getLesson()->schoolClass->school?->aiEnabled()
                ? __('Prüfe die Stufen, bevor du die Hausaufgabe freigibst.')
                : __('Demo-Modus – KI-Anbindung vorbereitet, aber nicht aktiv. Die Stufen sind Beispielentwürfe, bitte prüfe und passe sie an.')))
            ->persistent($done > 0)
            ->send();
    }

    private function getLesson(): Lesson
    {
        /** @var Lesson */
        return $this->getOwnerRecord();
    }
}
