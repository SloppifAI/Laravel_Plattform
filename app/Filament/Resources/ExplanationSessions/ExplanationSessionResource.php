<?php

namespace App\Filament\Resources\ExplanationSessions;

use App\Filament\NavigationGroup;
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

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Unterricht;

    protected static ?int $navigationSort = 3;

    protected static ?string $slug = 'erklaer-sitzungen';

    protected static ?string $recordTitleAttribute = 'topic';

    public static function getModelLabel(): string
    {
        return __('Erklär-Sitzung');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Erklär-Sitzungen');
    }

    public static function getTitleCaseModelLabel(): string
    {
        return __('Erklär-Sitzung');
    }

    public static function getTitleCasePluralModelLabel(): string
    {
        return __('Erklär-Sitzungen');
    }

    public static function getNavigationLabel(): string
    {
        return __('Erklär-Sitzungen');
    }

    public static function infolist(Schema $schema): Schema
    {
        return ExplanationSessionInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ExplanationSessionsTable::configure($table);
    }

    /**
     * Teachers only see sessions about their own lessons (sessions without a lesson are not theirs), admins the sessions
     * of the students of their school. The policy alone does not filter records.
     *
     * @return Builder<ExplanationSession>
     */
    public static function getEloquentQuery(): Builder
    {
        /** @var User $user */
        $user = auth()->user();

        return parent::getEloquentQuery()->when(
            $user->isAdmin(),
            // Only sessions about a lesson of the own school: free-topic sessions stay private to the student.
            fn (Builder $query) => $query->whereHas('lesson', fn (Builder $lessons) => $lessons->whereRelation('schoolClass', 'school_id', $user->school_id)),
            // Like StudentQuestion::scopeManagedBy(): not the lessons of a class the teacher is in only as a substitute whose substitution is over.
            fn (Builder $query) => $query->whereHas('lesson', fn (Builder $lessons) => $lessons
                ->whereBelongsTo($user, 'teacher')
                ->whereHas('schoolClass', fn (Builder $classes) => $classes->where(fn (Builder $classes) => $classes
                    ->whereDoesntHave('substitutions', fn (Builder $substitutions) => $substitutions->where('substitute_id', $user->getKey()))
                    ->orWhere(fn (Builder $taught) => $taught->taughtBy($user))))),
        );
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
            ->placeholder(__('Noch nicht ausgewertet'));
    }

    /**
     * Running or finished, as text plus icon. The state stays a language-independent key, only its label is translated.
     */
    public static function asStatusBadge(TextColumn|TextEntry $component): TextColumn|TextEntry
    {
        return $component
            ->state(fn (ExplanationSession $record): string => $record->isFinished() ? 'finished' : 'running')
            ->badge()
            ->formatStateUsing(fn (string $state): string => $state === 'finished' ? __('Beendet') : __('Läuft'))
            ->color(fn (string $state): string => $state === 'finished' ? 'gray' : 'info')
            ->icon(fn (string $state): Heroicon => $state === 'finished' ? Heroicon::OutlinedFlag : Heroicon::OutlinedClock);
    }
}
