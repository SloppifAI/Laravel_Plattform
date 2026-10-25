<?php

namespace App\Filament\Resources\Lessons\Tables;

use App\Models\Lesson;
use App\UnderstandingLevel;
use Carbon\Carbon;
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
                    ->label(__('Datum'))
                    ->formatStateUsing(fn (?Carbon $state): ?string => $state?->isoFormat(__('DD.MM.YYYY')))
                    ->sortable(),
                TextColumn::make('schoolClass.name')
                    ->label(__('Klasse'))
                    ->sortable(),
                TextColumn::make('subject')
                    ->label(__('Fach'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('topic')
                    ->label(__('Thema'))
                    ->searchable()
                    ->wrap()
                    // Right under the topic: no extra column, and the stacked cards of a phone show it too.
                    ->description(fn (Lesson $record) => $record->isPracticeTask() ? view('filament.resources.lessons.practice-badge') : null),
                TextColumn::make('status')
                    ->label(__('Status'))
                    ->state(fn (Lesson $record): string => $record->isPublished() ? __('Freigegeben') : __('Wartet auf Freigabe'))
                    ->badge()
                    ->color(fn (string $state): string => $state === __('Freigegeben') ? 'primary' : 'warning')
                    ->icon(fn (string $state): Heroicon => $state === __('Freigegeben') ? Heroicon::OutlinedCheckCircle : Heroicon::OutlinedClock),
                TextColumn::make('submissions_count')
                    ->label(__('Abgegeben'))
                    // Hand-in counts and the average are results: for a Förderaufgabe of one student they are that student's own.
                    ->state(fn (Lesson $record): ?string => $record->isPublished() && auth()->user()->can('viewResults', $record) ? "{$record->submissions_count} / {$record->students_count}" : null)
                    ->placeholder('–'),
                TextColumn::make('submissions_avg_understanding_score')
                    ->label(__('Ø Verständnis'))
                    ->state(fn (Lesson $record): float|string|null => auth()->user()->can('viewResults', $record) ? $record->submissions_avg_understanding_score : null)
                    ->badge()
                    ->formatStateUsing(fn (float|string $state): string => round((float) $state).' % · '.$levelOf($state)->getLabel())
                    ->color(fn (float|string $state): string => $levelOf($state)->getColor())
                    ->icon(fn (float|string $state): Heroicon => $levelOf($state)->getIcon())
                    ->placeholder('–')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('school_class_id')
                    ->label(__('Klasse'))
                    ->relationship('schoolClass', 'name', fn (Builder $query): Builder => $query->where('school_id', auth()->user()->school_id))
                    ->preload(),
                TernaryFilter::make('published_at')
                    ->label(__('Status'))
                    ->nullable()
                    ->placeholder(__('Alle'))
                    ->trueLabel(__('Freigegeben'))
                    ->falseLabel(__('Wartet auf Freigabe')),
                TernaryFilter::make('practice_competency_id')
                    ->label(__('Art'))
                    ->nullable()
                    ->placeholder(__('Alle'))
                    ->trueLabel(__('Nur Förderaufgaben'))
                    ->falseLabel(__('Nur Stunden')),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    // Checks each lesson, so one that holds hand-ins its teacher may no longer read is skipped.
                    DeleteBulkAction::make()
                        ->authorizeIndividualRecords('delete'),
                ]),
            ]);
    }
}
