<?php

namespace App\Filament\Resources\Lessons\RelationManagers;

use App\Filament\Resources\Lessons\Pages\EditLesson;
use App\Models\Competency;
use App\Models\Lesson;
use App\QuestionType;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\On;

#[On(EditLesson::HOMEWORK_CHANGED_EVENT)]
class QuestionsRelationManager extends RelationManager
{
    protected static string $relationship = 'questions';

    protected static ?string $title = 'Hausaufgabe';

    protected static ?string $modelLabel = 'Frage';

    protected static ?string $pluralModelLabel = 'Fragen';

    /**
     * Once delegated, students may already be answering, and deleting a question would delete their answers.
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
                    ->label('Art')
                    ->options(QuestionType::class)
                    ->default(QuestionType::Comprehension)
                    ->required(),
                Select::make('competency_id')
                    ->label('Kompetenz')
                    ->relationship(
                        'competency',
                        'name',
                        modifyQueryUsing: fn (Builder $query): Builder => $query->forSubject($this->getLesson()->subject)->orderBy('area')->orderBy('name'),
                    )
                    ->getOptionLabelFromRecordUsing(fn (Competency $record): string => "{$record->area}: {$record->name}")
                    ->searchable(['area', 'name'])
                    ->preload(),
                Textarea::make('prompt')
                    ->label('Frage')
                    ->rows(3)
                    ->required()
                    ->columnSpanFull(),
                Textarea::make('expected_answer')
                    ->label('Erwartungshorizont (nur für dich sichtbar)')
                    ->helperText('Daran misst die KI die Antworten der Schüler:innen.')
                    ->rows(4)
                    ->required()
                    ->columnSpanFull(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('prompt')
            ->description(fn (): ?string => $this->isReadOnly()
                ? 'Die Hausaufgabe ist verteilt. Um Fragen zu ändern, ziehe die Verteilung zurück (nur möglich, solange niemand abgegeben hat).'
                : null)
            ->reorderable('position')
            ->columns([
                TextColumn::make('position')
                    ->label('Nr.'),
                TextColumn::make('type')
                    ->label('Art')
                    ->badge(),
                TextColumn::make('prompt')
                    ->label('Frage')
                    ->wrap(),
                TextColumn::make('competency.name')
                    ->label('Kompetenz')
                    ->placeholder('–')
                    ->wrap(),
            ])
            ->headerActions([
                CreateAction::make()
                    ->mutateDataUsing(fn (array $data): array => [
                        ...$data,
                        'position' => ($this->getLesson()->questions()->max('position') ?? 0) + 1,
                    ]),
            ])
            ->recordActions([
                ViewAction::make()
                    ->hidden(fn (): bool => ! $this->isReadOnly()),
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    private function getLesson(): Lesson
    {
        /** @var Lesson */
        return $this->getOwnerRecord();
    }
}
