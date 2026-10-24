<?php

namespace App\Filament\Resources\SchoolClasses;

use App\Filament\NavigationGroup;
use App\Filament\Resources\SchoolClasses\Pages\CreateSchoolClass;
use App\Filament\Resources\SchoolClasses\Pages\EditSchoolClass;
use App\Filament\Resources\SchoolClasses\Pages\ListSchoolClasses;
use App\Filament\Resources\SchoolClasses\RelationManagers\StudentsRelationManager;
use App\Filament\Resources\SchoolClasses\RelationManagers\SubstitutionsRelationManager;
use App\Filament\Resources\SchoolClasses\RelationManagers\TeachersRelationManager;
use App\Filament\Resources\SchoolClasses\Schemas\SchoolClassForm;
use App\Filament\Resources\SchoolClasses\Tables\SchoolClassesTable;
use App\Models\SchoolClass;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class SchoolClassResource extends Resource
{
    protected static ?string $model = SchoolClass::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Verwaltung;

    protected static ?int $navigationSort = 2;

    protected static ?string $slug = 'klassen';

    protected static ?string $recordTitleAttribute = 'name';

    public static function getModelLabel(): string
    {
        return __('Klasse');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Klassen');
    }

    /**
     * A school administration sees only the classes of its own school, because a class code is all it takes to join.
     *
     * @return Builder<SchoolClass>
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('school_id', auth()->user()?->school_id)
            ->whereNotNull('school_id');
    }

    public static function form(Schema $schema): Schema
    {
        return SchoolClassForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SchoolClassesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            StudentsRelationManager::class,
            TeachersRelationManager::class,
            SubstitutionsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSchoolClasses::route('/'),
            'create' => CreateSchoolClass::route('/create'),
            'edit' => EditSchoolClass::route('/{record}/edit'),
        ];
    }
}
