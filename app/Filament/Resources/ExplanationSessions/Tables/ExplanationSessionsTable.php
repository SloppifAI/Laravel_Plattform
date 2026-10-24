<?php

namespace App\Filament\Resources\ExplanationSessions\Tables;

use App\Filament\Resources\ExplanationSessions\ExplanationSessionResource;
use App\Filament\Resources\Lessons\LessonResource;
use App\Models\ExplanationSession;
use App\Models\Lesson;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ExplanationSessionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['student.schoolClass', 'lesson']))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('student.name')
                    ->label(__('Schüler:in'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('student.schoolClass.name')
                    ->label(__('Klasse'))
                    ->placeholder('–'),
                TextColumn::make('topic')
                    ->label(__('Thema'))
                    ->searchable()
                    ->wrap(),
                TextColumn::make('lesson')
                    ->label(__('Unterrichtsstunde'))
                    ->state(fn (ExplanationSession $record): ?string => $record->lesson ? "{$record->lesson->subject}: {$record->lesson->topic}" : null)
                    ->placeholder('–')
                    ->wrap(),
                TextColumn::make('created_at')
                    ->label(__('Gestartet'))
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
                ExplanationSessionResource::asStatusBadge(TextColumn::make('status')->label(__('Status'))),
                ExplanationSessionResource::asUnderstandingBadge(TextColumn::make('understanding_score')->label(__('Verständnis')))
                    ->placeholder('–')
                    ->sortable(),
            ])
            ->filters([
                // Built from the resource query of lessons, so teachers never see the lessons of colleagues.
                SelectFilter::make('lesson_id')
                    ->label(__('Unterrichtsstunde'))
                    ->options(fn (): array => LessonResource::getEloquentQuery()
                        ->orderByDesc('held_on')
                        ->get()
                        ->mapWithKeys(fn (Lesson $lesson): array => [$lesson->id => "{$lesson->subject}: {$lesson->topic} ({$lesson->held_on->format('d.m.Y')})"])
                        ->all())
                    ->searchable(),
                TernaryFilter::make('finished_at')
                    ->label(__('Status'))
                    ->nullable()
                    ->placeholder(__('Alle'))
                    ->trueLabel(__('Beendet'))
                    ->falseLabel(__('Läuft')),
            ])
            ->recordActions([
                ViewAction::make()
                    ->label(__('Ansehen')),
            ]);
    }
}
