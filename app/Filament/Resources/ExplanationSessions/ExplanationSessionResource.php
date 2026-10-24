<?php

namespace App\Filament\Resources\ExplanationSessions;

use App\Filament\Resources\ExplanationSessions\Pages\ListExplanationSessions;
use App\Filament\Resources\ExplanationSessions\Pages\ViewExplanationSession;
use App\Filament\Resources\ExplanationSessions\Schemas\ExplanationSessionInfolist;
use App\Filament\Resources\ExplanationSessions\Tables\ExplanationSessionsTable;
use App\Models\ExplanationSession;
use App\Models\User;
use App\UnderstandingLevel;
use BackedEnum;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Read-only view for teachers (and admins) on how students explain topics of their lessons in "Erklär's mir".
 */
class ExplanationSessionResource extends Resource
{
    protected static ?string $model = ExplanationSession::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected static string|UnitEnum|null $navigationGroup = 'Unterricht';

    protected static ?int $navigationSort = 3;

    protected static ?string $modelLabel = 'Erklär-Sitzung';

    protected static ?string $pluralModelLabel = 'Erklär-Sitzungen';

    protected static ?string $slug = 'erklaer-sitzungen';

    protected static ?string $recordTitleAttribute = 'topic';

    public static function infolist(Schema $schema): Schema
    {
        return ExplanationSessionInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ExplanationSessionsTable::configure($table);
    }

    /**
     * Teachers only see sessions about their own lessons (sessions without a lesson are not theirs), admins all of them.
     * The policy alone does not filter records.
     *
     * @return Builder<ExplanationSession>
     */
    public static function getEloquentQuery(): Builder
    {
        /** @var User $user */
        $user = auth()->user();

        return parent::getEloquentQuery()
            ->unless($user->isAdmin(), fn (Builder $query) => $query->whereHas(
                'lesson',
                fn (Builder $lessons) => $lessons->whereBelongsTo($user, 'teacher'),
            ));
    }

    public static function getPages(): array
    {
        return [
            'index' => ListExplanationSessions::route('/'),
            'view' => ViewExplanationSession::route('/{record}'),
        ];
    }

    /**
     * Shows a 0–100 score as percentage plus UnderstandingLevel label and icon, so it never relies on colour alone.
     */
    public static function asUnderstandingBadge(TextColumn|TextEntry $component): TextColumn|TextEntry
    {
        return $component
            ->badge()
            ->formatStateUsing(fn (int $state): string => "{$state} % · ".UnderstandingLevel::fromScore($state)->getLabel())
            ->color(fn (int $state): string => UnderstandingLevel::fromScore($state)->getColor())
            ->icon(fn (int $state): Heroicon => UnderstandingLevel::fromScore($state)->getIcon())
            ->placeholder('Noch nicht ausgewertet');
    }

    /**
     * Running or finished, as text plus icon.
     */
    public static function asStatusBadge(TextColumn|TextEntry $component): TextColumn|TextEntry
    {
        return $component
            ->state(fn (ExplanationSession $record): string => $record->isFinished() ? 'Beendet' : 'Läuft')
            ->badge()
            ->color(fn (string $state): string => $state === 'Beendet' ? 'gray' : 'info')
            ->icon(fn (string $state): Heroicon => $state === 'Beendet' ? Heroicon::OutlinedFlag : Heroicon::OutlinedClock);
    }
}
