<?php

namespace App\Filament\Resources\Schools;

use App\Filament\NavigationGroup;
use App\Filament\Resources\Schools\Pages\EditSchool;
use App\Filament\Resources\Schools\Pages\ListSchools;
use App\Filament\Resources\Schools\Schemas\SchoolForm;
use App\Filament\Resources\Schools\Tables\SchoolsTable;
use App\Models\School;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * The platform operator's overview of all registered schools. Schools register themselves, so there is no create page,
 * and a school is blocked rather than deleted.
 */
class SchoolResource extends Resource
{
    protected static ?string $model = School::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingLibrary;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Plattform;

    protected static ?int $navigationSort = 1;

    protected static ?string $slug = 'schulen';

    protected static ?string $recordTitleAttribute = 'name';

    public static function getModelLabel(): string
    {
        return __('Schule');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Schulen');
    }

    /**
     * SchoolPolicy lets a school administration update its own school, but that happens on "Meine Schule", not here.
     */
    public static function canEdit(Model $record): bool
    {
        return (auth()->user()?->isOperator() ?? false) && parent::canEdit($record);
    }

    public static function form(Schema $schema): Schema
    {
        return SchoolForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SchoolsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSchools::route('/'),
            'edit' => EditSchool::route('/{record}/edit'),
        ];
    }
}
