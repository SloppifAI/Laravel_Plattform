<?php

namespace App\Filament\Resources\Lessons\Tables;

use App\Models\Lesson;
use App\UnderstandingLevel;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class LessonsTable
{
    public static function configure(Table $table): Table
    {
        $levelOf = fn (float|string $averageScore): UnderstandingLevel => UnderstandingLevel::fromScore((int) round((float) $averageScore));

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->with('schoolClass')
                ->withCount(['students', 'submissions'])
                ->withAvg('submissions', 'understanding_score'))
            ->defaultSort('held_on', 'desc')
            ->columns([
                TextColumn::make('held_on')
                    ->label('Datum')
                    ->date('d.m.Y')
                    ->sortable(),
                TextColumn::make('schoolClass.name')
                    ->label('Klasse')
                    ->sortable(),
                TextColumn::make('subject')
                    ->label('Fach')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('topic')
                    ->label('Thema')
                    ->searchable()
                    ->wrap()
                    // Right under the topic: no extra column, and the stacked cards of a phone show it too.
                    ->description(fn (Lesson $record) => $record->isPracticeTask() ? view('filament.resources.lessons.practice-badge') : null),
                TextColumn::make('status')
                    ->label('Status')
                    ->state(fn (Lesson $record): string => $record->isPublished() ? 'Freigegeben' : 'Wartet auf Freigabe')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'Freigegeben' ? 'primary' : 'warning')
                    ->icon(fn (string $state): Heroicon => $state === 'Freigegeben' ? Heroicon::OutlinedCheckCircle : Heroicon::OutlinedClock),
                TextColumn::make('submissions_count')
                    ->label('Abgegeben')
                    ->state(fn (Lesson $record): ?string => $record->isPublished() ? "{$record->submissions_count} / {$record->students_count}" : null)
                    ->placeholder('–'),
                TextColumn::make('submissions_avg_understanding_score')
                    ->label('Ø Verständnis')
                    ->badge()
                    ->formatStateUsing(fn (float|string $state): string => round((float) $state).' % · '.$levelOf($state)->getLabel())
                    ->color(fn (float|string $state): string => $levelOf($state)->getColor())
                    ->icon(fn (float|string $state): Heroicon => $levelOf($state)->getIcon())
                    ->placeholder('–')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('school_class_id')
                    ->label('Klasse')
                    ->relationship('schoolClass', 'name', fn (Builder $query): Builder => $query->where('school_id', auth()->user()->school_id))
                    ->preload(),
                TernaryFilter::make('published_at')
                    ->label('Status')
                    ->nullable()
                    ->placeholder('Alle')
                    ->trueLabel('Freigegeben')
                    ->falseLabel('Wartet auf Freigabe'),
                TernaryFilter::make('practice_competency_id')
                    ->label('Art')
                    ->nullable()
                    ->placeholder('Alle')
                    ->trueLabel('Nur Förderaufgaben')
                    ->falseLabel('Nur Stunden'),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
