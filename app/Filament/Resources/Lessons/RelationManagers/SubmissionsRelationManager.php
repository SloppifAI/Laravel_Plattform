<?php

namespace App\Filament\Resources\Lessons\RelationManagers;

use App\Filament\Resources\Lessons\Pages\EditLesson;
use App\MistakeSource;
use App\MistakeType;
use App\Models\Answer;
use App\Models\Lesson;
use App\Models\Submission;
use App\QuestionLevel;
use App\Support\LearningInsights;
use App\UnderstandingLevel;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Livewire\Attributes\On;

/**
 * The students' homework as handed in. Teachers read it and may correct the mistake types (Fehlerdiagnose); returning a
 * submission deletes it so the student can redo it.
 * Only for those who may read the lesson's results (LessonPolicy::viewResults): a substitute whose period is over keeps
 * the lesson as a draft, but no longer sees what the students handed in.
 */
#[On(EditLesson::HOMEWORK_CHANGED_EVENT)]
class SubmissionsRelationManager extends RelationManager
{
    protected static string $relationship = 'submissions';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Abgaben');
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return parent::canViewForRecord($ownerRecord, $pageClass) && (auth()->user()?->can('viewResults', $ownerRecord) ?? false);
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('Ergebnis der KI-Prüfung'))
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        self::asUnderstandingBadge(TextEntry::make('understanding_score')->label(__('Verständnis'))),
                        TextEntry::make('created_at')
                            ->label(__('Abgegeben am'))
                            ->dateTime('d.m.Y H:i'),
                        TextEntry::make('feedback')
                            ->label(__('Rückmeldung'))
                            ->placeholder('–')
                            ->columnSpanFull(),
                        TextEntry::make('gaps')
                            ->label(__('Lücken'))
                            ->bulleted()
                            ->listWithLineBreaks()
                            ->placeholder(__('Keine Lücken erkannt'))
                            ->columnSpanFull(),
                    ]),
                Section::make(__('Zusammenfassung der Schüler:in'))
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('summary')
                            ->hiddenLabel(),
                    ]),
                Section::make(__('Antworten'))
                    ->columnSpanFull()
                    ->schema([
                        RepeatableEntry::make('answers')
                            ->hiddenLabel()
                            ->schema([
                                TextEntry::make('question.prompt')
                                    ->label(__('Frage'))
                                    ->state(fn (Answer $record): string => $record->askedPrompt()),
                                TextEntry::make('level')
                                    ->label(__('Stufe'))
                                    ->badge()
                                    ->color('gray')
                                    ->state(fn (Answer $record): ?string => $record->level === null ? null : $record->question->levelFor($record->level)->getLabel())
                                    ->visible(fn (Answer $record): bool => $record->level !== null && $record->question->levelFor($record->level) !== QuestionLevel::Standard),
                                TextEntry::make('answer')
                                    ->label(__('Antwort')),
                                self::asUnderstandingBadge(TextEntry::make('score')->label(__('Bewertung'))),
                                TextEntry::make('feedback')
                                    ->label(__('Rückmeldung'))
                                    ->placeholder('–'),
                                TextEntry::make('mistake_type')
                                    ->label(__('Fehlerart'))
                                    ->badge()
                                    ->color('gray')
                                    ->hint(fn (Answer $record): string => $record->mistake_source === MistakeSource::Teacher ? __('Von einer Lehrkraft eingeordnet') : __('KI-Hinweis, kann falsch sein'))
                                    ->visible(fn (Answer $record): bool => $record->mistake_type !== null && self::canSeeMistakes($record->submission)),
                            ]),
                    ]),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modelLabel(__('Abgabe'))
            ->pluralModelLabel(__('Abgaben'))
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('student'))
            ->description(fn (): string => $this->describePendingStudents())
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('student.name')
                    ->label(__('Schüler:in'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label(__('Abgegeben am'))
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
                self::asUnderstandingBadge(TextColumn::make('understanding_score')->label(__('Verständnis')))
                    ->sortable(),
            ])
            ->recordActions([
                ViewAction::make()
                    ->label(__('Ansehen')),
                self::correctMistakesAction(),
                DeleteAction::make()
                    ->label(__('Zur Überarbeitung zurückgeben'))
                    ->icon(Heroicon::OutlinedArrowUturnLeft)
                    ->modalHeading(__('Zur Überarbeitung zurückgeben?'))
                    ->modalDescription(__('Die Abgabe samt KI-Prüfung wird gelöscht. Die Schüler:in kann die Hausaufgabe danach neu bearbeiten.'))
                    ->modalSubmitActionLabel(__('Zurückgeben'))
                    ->successNotificationTitle(__('Abgabe zurückgegeben')),
            ]);
    }

    /**
     * The teacher's correction of the mistake types (Fehlerdiagnose): a changed type counts as the teacher's
     * (`mistake_source = teacher`), drops the AI's confidence and is never overwritten by a re-evaluation. An unchanged
     * one keeps its source. Only a hint: no score changes.
     */
    private static function correctMistakesAction(): Action
    {
        return Action::make('correctMistakes')
            ->label(__('Fehlerart korrigieren'))
            ->icon(Heroicon::OutlinedPencilSquare)
            ->color('gray')
            ->visible(fn (Submission $record): bool => $record->isEvaluated() && self::canSeeMistakes($record))
            ->modalHeading(__('Fehlerart korrigieren'))
            ->modalDescription(__('Die Fehlerart ist nur ein Hinweis und ändert keine Punkte. Deine Einordnung hat Vorrang und wird bei einer neuen Auswertung nicht überschrieben.'))
            ->fillForm(fn (Submission $record): array => [
                'mistakes' => $record->answers->mapWithKeys(fn (Answer $answer): array => [$answer->getKey() => $answer->mistake_type?->value])->all(),
            ])
            ->schema(fn (Submission $record): array => $record->answers->map(fn (Answer $answer): Select => Select::make("mistakes.{$answer->getKey()}")
                ->label($answer->askedPrompt())
                ->helperText(__('Antwort: :answer', ['answer' => Str::limit((string) $answer->answer, 300)]))
                ->options(MistakeType::class)
                ->placeholder(__('Keine Fehlerart')))->all())
            ->action(function (Submission $record, array $data): void {
                foreach ($record->answers as $answer) {
                    $value = $data['mistakes'][$answer->getKey()] ?? null;
                    $type = $value instanceof MistakeType ? $value : MistakeType::tryFrom((string) $value);

                    if ($type !== $answer->mistake_type) {
                        $answer->update(['mistake_type' => $type, 'mistake_confidence' => null, 'mistake_source' => MistakeSource::Teacher]);
                    }
                }
            })
            ->successNotificationTitle(__('Fehlerart gespeichert'));
    }

    /**
     * Mistake types are learning results: shown only to whoever may see the student's learning progress anyway.
     */
    private static function canSeeMistakes(Submission $submission): bool
    {
        $user = auth()->user();

        return $user !== null && LearningInsights::canView($user, $submission->student);
    }

    /**
     * Shows a 0–100 score as percentage plus UnderstandingLevel label and icon, so it never relies on colour alone.
     */
    private static function asUnderstandingBadge(TextColumn|TextEntry $component): TextColumn|TextEntry
    {
        return $component
            ->badge()
            ->formatStateUsing(fn (int $state): string => "{$state} % · ".UnderstandingLevel::fromScore($state)->getLabel())
            ->color(fn (int $state): string => UnderstandingLevel::fromScore($state)->getColor())
            ->icon(fn (int $state): Heroicon => UnderstandingLevel::fromScore($state)->getIcon())
            ->placeholder(__('Wird noch geprüft'));
    }

    private function describePendingStudents(): string
    {
        /** @var Lesson $lesson */
        $lesson = $this->getOwnerRecord();

        if (! $lesson->isPublished()) {
            return __('Die Hausaufgabe ist noch nicht freigegeben.');
        }

        $pendingNames = $lesson->students()
            ->whereDoesntHave('submissions', fn (Builder $query): Builder => $query->whereBelongsTo($lesson))
            ->orderBy('name')
            ->pluck('name');

        return $pendingNames->isEmpty()
            ? __('Alle Schüler:innen haben abgegeben.')
            : __('Noch nicht abgegeben (:count): :names', ['count' => $pendingNames->count(), 'names' => $pendingNames->join(', ')]);
    }
}
