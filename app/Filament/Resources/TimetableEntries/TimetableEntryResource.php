<?php

namespace App\Filament\Resources\TimetableEntries;

use App\Filament\NavigationGroup;
use App\Filament\Resources\TimetableEntries\Pages\ManageTimetableEntries;
use App\Filament\Resources\TimetableEntries\Schemas\TimetableEntryForm;
use App\Filament\Resources\TimetableEntries\Tables\TimetableEntriesTable;
use App\Models\TimetableEntry;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * The timetables of the classes. A school administration maintains those of its own school (TimetableEntryPolicy).
 * Students and teachers read their week on the page "Stundenplan".
 */
class TimetableEntryResource extends Resource
{
    protected static ?string $model = TimetableEntry::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Verwaltung;

    protected static ?int $navigationSort = 3;

    protected static ?string $slug = 'stundenplaene';

    public static function getNavigationLabel(): string
    {
        return __('Stundenpläne');
    }

    public static function getModelLabel(): string
    {
        return __('Stundenplan-Eintrag');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Stundenplan-Einträge');
    }

    /**
     * The school boundary: a school administration only finds the entries of its own school's classes (others are a 404).
     *
     * @return Builder<TimetableEntry>
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->ofSchool(auth()->user()?->school_id);
    }

    public static function form(Schema $schema): Schema
    {
        return TimetableEntryForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return TimetableEntriesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageTimetableEntries::route('/'),
        ];
    }
}
