<?php

namespace App\Filament\Resources\SchoolClasses\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Support\Enums\FontFamily;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class SchoolClassesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('Klasse'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('teacher.name')
                    ->label(__('Klassenleitung'))
                    ->placeholder(__('Noch keine'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('teachers_count')
                    ->label(__('Weitere Lehrkräfte'))
                    ->counts('teachers')
                    ->sortable(),
                TextColumn::make('join_code')
                    ->label(__('Klassencode'))
                    ->fontFamily(FontFamily::Mono)
                    ->copyable()
                    ->copyMessage(__('Code kopiert'))
                    ->searchable(),
                TextColumn::make('students_count')
                    ->label(__('Schüler:innen'))
                    ->counts('students')
                    ->sortable(),
            ])
            ->defaultSort('name')
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
