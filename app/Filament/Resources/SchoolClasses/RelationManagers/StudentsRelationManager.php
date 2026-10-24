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
use Illuminate\Database\Eloquent\Model;

/**
 * Students of a class. Accounts themselves are created in the user management; here they are only moved in and out.
 */
class StudentsRelationManager extends RelationManager
{
    protected static string $relationship = 'students';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Schüler:innen');
    }

    protected static function getModelLabel(): ?string
    {
        return __('Schüler:in');
    }

    protected static function getPluralModelLabel(): ?string
    {
        return __('Schüler:innen');
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')
                    ->label(__('Name'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('username')
                    ->label(__('Benutzername'))
                    ->searchable(),
            ])
            ->defaultSort('name')
            ->emptyStateHeading(__('Noch keine Schüler:innen in dieser Klasse'))
            ->headerActions([
                AssociateAction::make()
                    ->label(__('Schüler:innen hinzufügen'))
                    ->modalHeading(__('Schüler:innen zur Klasse hinzufügen'))
                    ->recordSelectOptionsQuery(fn (Builder $query): Builder => $query->withRole(Role::Student)->where('school_id', $this->getOwnerRecord()->school_id))
                    ->multiple()
                    ->preloadRecordSelect(),
            ])
            ->recordActions([
                DissociateAction::make()
                    ->label(__('Aus Klasse entfernen'))
                    ->modalHeading(__('Aus Klasse entfernen')),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DissociateBulkAction::make()
                        ->label(__('Aus Klasse entfernen')),
                ]),
            ]);
    }
}
