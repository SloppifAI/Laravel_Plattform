<?php

namespace App\Filament\Resources\Competencies;

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

    protected static ?string $navigationLabel = 'Kompetenzraster';

    protected static ?string $modelLabel = 'Kompetenz';

    protected static ?string $pluralModelLabel = 'Kompetenzen';

    protected static ?string $slug = 'kompetenzraster';

    protected static ?string $recordTitleAttribute = 'name';

    /**
     * The operator maintains the framework with the platform tools. School administrations look it up with their
     * administration, teachers while planning lessons, so it sits with their lesson tools.
     */
    public static function getNavigationGroup(): string|UnitEnum|null
    {
        $user = auth()->user();

        return match (true) {
            $user?->isOperator() => 'Plattform',
            $user?->isAdmin() => 'Verwaltung',
            default => 'Unterricht',
        };
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('subject')
                    ->label('Fach')
                    ->helperText('Leer lassen, wenn die Kompetenz fachübergreifend gilt.')
                    ->datalist(fn (): array => self::distinctValues('subject'))
                    ->maxLength(255),
                TextInput::make('area')
                    ->label('Kompetenzbereich')
                    ->placeholder('z. B. Argumentieren')
                    ->datalist(fn (): array => self::distinctValues('area'))
                    ->required()
                    ->maxLength(255),
                TextInput::make('name')
                    ->label('Kompetenz')
                    ->required()
                    ->maxLength(255)
                    ->columnSpanFull(),
                Textarea::make('description')
                    ->label('Beschreibung')
                    ->rows(4)
                    ->columnSpanFull(),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('subject')
                    ->label('Fach')
                    ->placeholder('Fachübergreifend'),
                TextEntry::make('area')
                    ->label('Kompetenzbereich'),
                TextEntry::make('name')
                    ->label('Kompetenz')
                    ->columnSpanFull(),
                TextEntry::make('description')
                    ->label('Beschreibung')
                    ->placeholder('Keine Beschreibung')
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')
                    ->label('Kompetenz')
                    ->description(fn (Competency $record): ?string => $record->description)
                    ->wrap()
                    ->searchable(['name', 'description']),
                TextColumn::make('area')
                    ->label('Kompetenzbereich')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('subject')
                    ->label('Fach')
                    ->placeholder('Fachübergreifend')
                    ->searchable()
                    ->sortable(),
            ])
            ->groups([
                Group::make('subject')
                    ->label('Fach')
                    ->getTitleFromRecordUsing(fn (Competency $record): string => $record->subject ?? 'Fachübergreifend')
                    ->collapsible(),
                Group::make('area')
                    ->label('Kompetenzbereich')
                    ->collapsible(),
            ])
            ->defaultGroup('subject')
            ->defaultSort('area')
            ->filters([
                SelectFilter::make('subject')
                    ->label('Fach')
                    ->options(fn (): array => [self::CROSS_SUBJECT => 'Fachübergreifend'] + self::distinctValues('subject'))
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        null, '' => $query,
                        self::CROSS_SUBJECT => $query->whereNull('subject'),
                        default => $query->where('subject', $data['value']),
                    }),
                SelectFilter::make('area')
                    ->label('Kompetenzbereich')
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
