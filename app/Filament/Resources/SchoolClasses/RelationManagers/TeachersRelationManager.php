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
use Illuminate\Database\Eloquent\Model;

/**
 * Teachers who teach the class besides the Klassenleitung. Only they and the Klassenleitung may hold lessons in the
 * class and see its learning data. Only teachers of the class's own school can be assigned; the options query is applied
 * again when the action runs, so a forged id of another school's teacher is dropped.
 */
class TeachersRelationManager extends RelationManager
{
    protected static string $relationship = 'teachers';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Unterrichtende Lehrkräfte');
    }

    protected static function getModelLabel(): ?string
    {
        return __('Lehrkraft');
    }

    protected static function getPluralModelLabel(): ?string
    {
        return __('Lehrkräfte');
    }

    public function table(Table $table): Table
    {
        /** @var SchoolClass $schoolClass */
        $schoolClass = $this->getOwnerRecord();

        return $table
            ->recordTitleAttribute('name')
            ->inverseRelationship('taughtClasses')
            ->description($schoolClass->teacher
                ? __('Klassenleitung ist :name. Sie unterrichtet die Klasse immer und muss hier nicht eingetragen werden.', ['name' => $schoolClass->teacher->name])
                : __('Die Klasse hat noch keine Klassenleitung.'))
            ->columns([
                TextColumn::make('name')
                    ->label(__('Name'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('username')
                    ->label(__('Benutzername'))
                    ->searchable(),
                IconColumn::make('is_klassenleitung')
                    ->label(__('Klassenleitung'))
                    ->boolean()
                    ->state(fn (User $record): bool => $record->is($schoolClass->teacher)),
            ])
            ->defaultSort('name')
            ->emptyStateHeading(__('Noch keine weiteren Lehrkräfte zugeordnet'))
            ->emptyStateDescription(__('Nur die Klassenleitung und hier zugeordnete Lehrkräfte können in dieser Klasse Unterricht anlegen und die Lernstände der Klasse sehen.'))
            ->headerActions([
                AttachAction::make()
                    ->label(__('Lehrkräfte hinzufügen'))
                    ->modalHeading(__('Lehrkräfte der Klasse zuordnen'))
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
                    ->label(__('Nicht mehr zuordnen'))
                    ->modalHeading(__('Lehrkraft nicht mehr zuordnen')),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DetachBulkAction::make()
                        ->label(__('Nicht mehr zuordnen')),
                ]),
            ]);
    }
}
