<?php

namespace App\Filament\Resources\SchoolClasses\RelationManagers;

use App\Role;
use Filament\Actions\AssociateAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DissociateAction;
use Filament\Actions\DissociateBulkAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Students of a class. Accounts themselves are created in the user management; here they are only moved in and out.
 */
class StudentsRelationManager extends RelationManager
{
    protected static string $relationship = 'students';

    protected static ?string $title = 'Schüler:innen';

    protected static ?string $modelLabel = 'Schüler:in';

    protected static ?string $pluralModelLabel = 'Schüler:innen';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')
                    ->label('Name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('username')
                    ->label('Benutzername')
                    ->searchable(),
            ])
            ->defaultSort('name')
            ->emptyStateHeading('Noch keine Schüler:innen in dieser Klasse')
            ->headerActions([
                AssociateAction::make()
                    ->label('Schüler:innen hinzufügen')
                    ->modalHeading('Schüler:innen zur Klasse hinzufügen')
                    ->recordSelectOptionsQuery(fn (Builder $query): Builder => $query->withRole(Role::Student))
                    ->multiple()
                    ->preloadRecordSelect(),
            ])
            ->recordActions([
                DissociateAction::make()
                    ->label('Aus Klasse entfernen')
                    ->modalHeading('Aus Klasse entfernen'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DissociateBulkAction::make()
                        ->label('Aus Klasse entfernen'),
                ]),
            ]);
    }
}
