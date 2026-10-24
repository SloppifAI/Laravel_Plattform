<?php

namespace App\Filament\Resources\SchoolClasses\RelationManagers;

use App\Models\SchoolClass;
use App\Models\User;
use App\Role;
use Filament\Actions\AttachAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DetachAction;
use Filament\Actions\DetachBulkAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Teachers who teach the class besides the Klassenleitung. Only they and the Klassenleitung may hold lessons in the
 * class and see its learning data. Only teachers of the class's own school can be assigned; the options query is applied
 * again when the action runs, so a forged id of another school's teacher is dropped.
 */
class TeachersRelationManager extends RelationManager
{
    protected static string $relationship = 'teachers';

    protected static ?string $title = 'Unterrichtende Lehrkräfte';

    protected static ?string $modelLabel = 'Lehrkraft';

    protected static ?string $pluralModelLabel = 'Lehrkräfte';

    public function table(Table $table): Table
    {
        /** @var SchoolClass $schoolClass */
        $schoolClass = $this->getOwnerRecord();

        return $table
            ->recordTitleAttribute('name')
            ->inverseRelationship('taughtClasses')
            ->description($schoolClass->teacher
                ? "Klassenleitung ist {$schoolClass->teacher->name}. Sie unterrichtet die Klasse immer und muss hier nicht eingetragen werden."
                : 'Die Klasse hat noch keine Klassenleitung.')
            ->columns([
                TextColumn::make('name')
                    ->label('Name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('username')
                    ->label('Benutzername')
                    ->searchable(),
                IconColumn::make('is_klassenleitung')
                    ->label('Klassenleitung')
                    ->boolean()
                    ->state(fn (User $record): bool => $record->is($schoolClass->teacher)),
            ])
            ->defaultSort('name')
            ->emptyStateHeading('Noch keine weiteren Lehrkräfte zugeordnet')
            ->emptyStateDescription('Nur die Klassenleitung und hier zugeordnete Lehrkräfte können in dieser Klasse Unterricht anlegen und die Lernstände der Klasse sehen.')
            ->headerActions([
                AttachAction::make()
                    ->label('Lehrkräfte hinzufügen')
                    ->modalHeading('Lehrkräfte der Klasse zuordnen')
                    // Also applied when the action runs: a forged id of another school's teacher, or of one already assigned, is dropped.
                    ->recordSelectOptionsQuery(fn (Builder $query): Builder => $query
                        ->withRole(Role::Teacher)
                        ->where('users.school_id', $schoolClass->school_id)
                        ->whereDoesntHave('taughtClasses', fn (Builder $classes): Builder => $classes->whereKey($schoolClass->getKey())))
                    ->multiple()
                    ->preloadRecordSelect(),
            ])
            ->recordActions([
                DetachAction::make()
                    ->label('Nicht mehr zuordnen')
                    ->modalHeading('Lehrkraft nicht mehr zuordnen'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DetachBulkAction::make()
                        ->label('Nicht mehr zuordnen'),
                ]),
            ]);
    }
}
