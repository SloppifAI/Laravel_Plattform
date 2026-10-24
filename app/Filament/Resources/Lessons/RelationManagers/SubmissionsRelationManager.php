<?php

namespace App\Filament\Resources\Lessons\RelationManagers;

use App\Filament\Resources\Lessons\Pages\EditLesson;
use App\Models\Answer;
use App\Models\Lesson;
use App\QuestionLevel;
use App\UnderstandingLevel;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
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
use Livewire\Attributes\On;

/**
 * The students' homework as handed in. Teachers only read it; returning a submission deletes it so the student can redo it.
 * Only for those who may read the lesson's results (LessonPolicy::viewResults): a substitute whose period is over keeps
 * the lesson as a draft, but no longer sees what the students handed in.
 */
#[On(EditLesson::HOMEWORK_CHANGED_EVENT)]
class SubmissionsRelationManager extends RelationManager
{
    protected static string $relationship = 'submissions';

    protected static ?string $title = 'Abgaben';

    protected static ?string $modelLabel = 'Abgabe';

    protected static ?string $pluralModelLabel = 'Abgaben';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return parent::canViewForRecord($ownerRecord, $pageClass) && (auth()->user()?->can('viewResults', $ownerRecord) ?? false);
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Ergebnis der KI-Prüfung')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        self::asUnderstandingBadge(TextEntry::make('understanding_score')->label('Verständnis')),
                        TextEntry::make('created_at')
                            ->label('Abgegeben am')
                            ->dateTime('d.m.Y H:i'),
                        TextEntry::make('feedback')
                            ->label('Rückmeldung')
                            ->placeholder('–')
                            ->columnSpanFull(),
                        TextEntry::make('gaps')
                            ->label('Lücken')
                            ->bulleted()
                            ->listWithLineBreaks()
                            ->placeholder('Keine Lücken erkannt')
                            ->columnSpanFull(),
                    ]),
                Section::make('Zusammenfassung der Schüler:in')
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('summary')
                            ->hiddenLabel(),
                    ]),
                Section::make('Antworten')
                    ->columnSpanFull()
                    ->schema([
                        RepeatableEntry::make('answers')
                            ->hiddenLabel()
                            ->schema([
                                TextEntry::make('question.prompt')
                                    ->label('Frage')
                                    ->state(fn (Answer $record): string => $record->askedPrompt()),
                                TextEntry::make('level')
                                    ->label('Stufe')
                                    ->badge()
                                    ->color('gray')
                                    ->state(fn (Answer $record): ?string => $record->level === null ? null : $record->question->levelFor($record->level)->getLabel())
                                    ->visible(fn (Answer $record): bool => $record->level !== null && $record->question->levelFor($record->level) !== QuestionLevel::Standard),
                                TextEntry::make('answer')
                                    ->label('Antwort'),
                                self::asUnderstandingBadge(TextEntry::make('score')->label('Bewertung')),
                                TextEntry::make('feedback')
                                    ->label('Rückmeldung')
                                    ->placeholder('–'),
                            ]),
                    ]),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('student'))
            ->description(fn (): string => $this->describePendingStudents())
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('student.name')
                    ->label('Schüler:in')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label('Abgegeben am')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
                self::asUnderstandingBadge(TextColumn::make('understanding_score')->label('Verständnis'))
                    ->sortable(),
            ])
            ->recordActions([
                ViewAction::make()
                    ->label('Ansehen'),
                DeleteAction::make()
                    ->label('Zur Überarbeitung zurückgeben')
                    ->icon(Heroicon::OutlinedArrowUturnLeft)
                    ->modalHeading('Zur Überarbeitung zurückgeben?')
                    ->modalDescription('Die Abgabe samt KI-Prüfung wird gelöscht. Die Schüler:in kann die Hausaufgabe danach neu bearbeiten.')
                    ->modalSubmitActionLabel('Zurückgeben')
                    ->successNotificationTitle('Abgabe zurückgegeben'),
            ]);
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
            ->placeholder('Wird noch geprüft');
    }

    private function describePendingStudents(): string
    {
        /** @var Lesson $lesson */
        $lesson = $this->getOwnerRecord();

        if (! $lesson->isPublished()) {
            return 'Die Hausaufgabe ist noch nicht freigegeben.';
        }

        $pendingNames = $lesson->students()
            ->whereDoesntHave('submissions', fn (Builder $query): Builder => $query->whereBelongsTo($lesson))
            ->orderBy('name')
            ->pluck('name');

        return $pendingNames->isEmpty()
            ? 'Alle Schüler:innen haben abgegeben.'
            : "Noch nicht abgegeben ({$pendingNames->count()}): {$pendingNames->join(', ')}";
    }
}
