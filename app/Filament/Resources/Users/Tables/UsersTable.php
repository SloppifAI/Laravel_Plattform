<?php

namespace App\Filament\Resources\Users\Tables;

use App\Filament\Resources\Users\UserResource;
use App\Role;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        $actor = auth()->user();
        $isOperator = $actor?->isOperator() ?? false;
        $schoolId = $actor?->school_id;

        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('Name'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('username')
                    ->label(__('Benutzername'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('email')
                    ->label(__('E-Mail'))
                    ->placeholder('—')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('role')
                    ->label(__('Rolle'))
                    ->badge()
                    ->sortable(),
                TextColumn::make('school.name')
                    ->label(__('Schule'))
                    ->placeholder('—')
                    ->searchable()
                    ->sortable()
                    ->visible($isOperator),
                TextColumn::make('schoolClass.name')
                    ->label(__('Klasse'))
                    ->placeholder('—')
                    ->sortable()
                    ->visible(! $isOperator),
            ])
            ->defaultSort('name')
            ->filters([
                SelectFilter::make('role')
                    ->label(__('Rolle'))
                    ->options(collect(UserResource::assignableRoles())->mapWithKeys(fn (Role $role): array => [$role->value => $role->getLabel()])->all()),
                SelectFilter::make('school_id')
                    ->label(__('Schule'))
                    ->relationship('school', 'name')
                    ->searchable()
                    ->preload()
                    ->visible($isOperator),
                SelectFilter::make('school_class_id')
                    ->label(__('Klasse'))
                    ->relationship('schoolClass', 'name', fn (Builder $query): Builder => $query->where('school_id', $schoolId))
                    ->searchable()
                    ->preload()
                    ->visible(! $isOperator),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    // Checks each record, so admins selecting themselves are skipped instead of deleted.
                    DeleteBulkAction::make()
                        ->authorizeIndividualRecords('delete'),
                ]),
            ]);
    }
}
