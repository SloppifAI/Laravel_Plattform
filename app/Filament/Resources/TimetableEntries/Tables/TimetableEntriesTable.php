<?php

namespace App\Filament\Resources\TimetableEntries\Tables;

use App\Models\TimetableEntry;
use App\Role;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class TimetableEntriesTable
{
    public static function configure(Table $table): Table
    {
        $schoolId = auth()->user()?->school_id;

        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['schoolClass.school', 'teacher'])->inWeekOrder())
            ->columns([
                TextColumn::make('schoolClass.name')
                    ->label(__('Klasse'))
                    ->weight(FontWeight::Medium)
                    ->searchable(),
                TextColumn::make('weekday')
                    ->label(__('Wochentag'))
                    ->formatStateUsing(fn (TimetableEntry $record): string => $record->weekdayName()),
                TextColumn::make('period')
                    ->label(__('Stunde'))
                    ->formatStateUsing(fn (TimetableEntry $record): string => TimetableEntry::periodLabel($record->period)),
                TextColumn::make('subject')
                    ->label(__('Fach'))
                    ->searchable(),
                TextColumn::make('teacher.name')
                    ->label(__('Lehrkraft'))
                    ->placeholder(__('Noch offen'))
                    ->searchable(),
                TextColumn::make('room')
                    ->label(__('Raum'))
                    ->placeholder('–'),
            ])
            ->groups([
                Group::make('school_class_id')
                    ->label(__('Klasse'))
                    ->getTitleFromRecordUsing(fn (TimetableEntry $record): string => $record->schoolClass->name)
                    ->collapsible(),
            ])
            ->defaultGroup('school_class_id')
            ->filters([
                SelectFilter::make('school_class_id')
                    ->label(__('Klasse'))
                    ->relationship('schoolClass', 'name', fn (Builder $query): Builder => $query->where('school_id', $schoolId))
                    ->searchable()
                    ->preload(),
                SelectFilter::make('teacher_id')
                    ->label(__('Lehrkraft'))
                    ->relationship('teacher', 'name', fn (Builder $query): Builder => $query->withRole(Role::Teacher)->where('school_id', $schoolId))
                    ->searchable()
                    ->preload(),
                SelectFilter::make('weekday')
                    ->label(__('Wochentag'))
                    ->options(TimetableEntry::weekdays()),
            ])
            ->emptyStateHeading(__('Noch kein Stundenplan'))
            ->emptyStateDescription(__('Trage die Stunden der Klassen ein. Schüler:innen und Lehrkräfte sehen ihren Plan dann unter „Stundenplan“.'))
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->modalDescription(__('Die Stunde verschwindet aus dem Stundenplan der Klasse und der Lehrkraft.')),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->authorizeIndividualRecords('delete'),
                ]),
            ]);
    }
}
