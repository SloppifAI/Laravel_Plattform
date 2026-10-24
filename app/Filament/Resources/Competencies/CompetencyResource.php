<?php

namespace App\Filament\Resources\Competencies;

use App\Filament\NavigationGroup;
use App\Filament\Resources\Competencies\Pages\ManageCompetencies;
use App\Models\Competency;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * The competency framework (Referenzrahmen), shared by all schools. The platform operator maintains it,
 * school administrations and teachers look it up read-only (see CompetencyPolicy).
 */
class CompetencyResource extends Resource
{
    /**
     * Filter value for competencies without subject, which apply to every subject.
     */
    public const string CROSS_SUBJECT = '__fachuebergreifend';

    protected static ?string $model = Competency::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    protected static ?int $navigationSort = 3;

    protected static ?string $slug = 'kompetenzraster';

    protected static ?string $recordTitleAttribute = 'name';

    public static function getNavigationLabel(): string
    {
        return __('Kompetenzraster');
    }

    public static function getModelLabel(): string
    {
        return __('Kompetenz');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Kompetenzen');
    }

    /**
     * The operator maintains the framework with the platform tools. School administrations look it up with their
     * administration, teachers while planning lessons, so it sits with their lesson tools.
     */
    public static function getNavigationGroup(): string|UnitEnum|null
    {
        $user = auth()->user();

        return match (true) {
            $user?->isOperator() => NavigationGroup::Plattform,
            $user?->isAdmin() => NavigationGroup::Verwaltung,
            default => NavigationGroup::Unterricht,
        };
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('subject')
                    ->label(__('Fach'))
                    ->helperText(__('Leer lassen, wenn die Kompetenz fachübergreifend gilt.'))
                    ->datalist(fn (): array => self::distinctValues('subject'))
                    ->maxLength(255),
                TextInput::make('area')
                    ->label(__('Kompetenzbereich'))
                    ->placeholder(__('z. B. Argumentieren'))
                    ->datalist(fn (): array => self::distinctValues('area'))
                    ->required()
                    ->maxLength(255),
                TextInput::make('name')
                    ->label(__('Kompetenz'))
                    ->required()
                    ->maxLength(255)
                    ->columnSpanFull(),
                Textarea::make('description')
                    ->label(__('Beschreibung'))
                    ->rows(4)
                    ->columnSpanFull(),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('subject')
                    ->label(__('Fach'))
                    ->placeholder(__('Fachübergreifend')),
                TextEntry::make('area')
                    ->label(__('Kompetenzbereich')),
                TextEntry::make('name')
                    ->label(__('Kompetenz'))
                    ->columnSpanFull(),
                TextEntry::make('description')
                    ->label(__('Beschreibung'))
                    ->placeholder(__('Keine Beschreibung'))
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')
                    ->label(__('Kompetenz'))
                    ->description(fn (Competency $record): ?string => $record->description)
                    ->wrap()
                    ->searchable(['name', 'description']),
                TextColumn::make('area')
                    ->label(__('Kompetenzbereich'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('subject')
                    ->label(__('Fach'))
                    ->placeholder(__('Fachübergreifend'))
                    ->searchable()
                    ->sortable(),
            ])
            ->groups([
                Group::make('subject')
                    ->label(__('Fach'))
                    ->getTitleFromRecordUsing(fn (Competency $record): string => $record->subject ?? __('Fachübergreifend'))
                    ->collapsible(),
                Group::make('area')
                    ->label(__('Kompetenzbereich'))
                    ->collapsible(),
            ])
            ->defaultGroup('subject')
            ->defaultSort('area')
            ->filters([
                SelectFilter::make('subject')
                    ->label(__('Fach'))
                    ->options(fn (): array => [self::CROSS_SUBJECT => __('Fachübergreifend')] + self::distinctValues('subject'))
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        null, '' => $query,
                        self::CROSS_SUBJECT => $query->whereNull('subject'),
                        default => $query->where('subject', $data['value']),
                    }),
                SelectFilter::make('area')
                    ->label(__('Kompetenzbereich'))
                    ->options(fn (): array => self::distinctValues('area')),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageCompetencies::route('/'),
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function distinctValues(string $column): array
    {
        return Competency::query()
            ->whereNotNull($column)
            ->distinct()
            ->orderBy($column)
            ->pluck($column, $column)
            ->all();
    }
}
