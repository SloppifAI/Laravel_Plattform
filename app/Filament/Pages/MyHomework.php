<?php

namespace App\Filament\Pages;

use App\Models\Lesson;
use App\Models\User;
use App\UnderstandingLevel;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use UnitEnum;

/**
 * The student's list of homework delegated to them, with status and, once checked, how well they understood the lesson.
 */
class MyHomework extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $slug = 'hausaufgaben';

    protected static ?string $title = 'Meine Hausaufgaben';

    protected static string|UnitEnum|null $navigationGroup = 'Lernen';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static ?int $navigationSort = 1;

    protected static string|Htmlable|null $navigationBadgeTooltip = 'Noch nicht abgegeben';

    public static function canAccess(): bool
    {
        return self::student()?->isStudent() ?? false;
    }

    public static function getNavigationBadge(): ?string
    {
        $openCount = Lesson::assignedTo(self::student())
            ->whereDoesntHave('submissions', fn (Builder $query) => $query->whereBelongsTo(self::student(), 'student'))
            ->count();

        return $openCount > 0 ? (string) $openCount : null;
    }

    /**
     * Keeps the menu entry highlighted while the student works on one of the items.
     *
     * @return array<string>
     */
    public static function getNavigationItemActiveRoutePattern(): string|array
    {
        return [static::getRouteName(), WorkOnHomework::getRouteName()];
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }

    public function table(Table $table): Table
    {
        $student = self::student();

        return $table
            // Only published homework delegated to this student; never other students' or classes' lessons.
            ->query(Lesson::assignedTo($student)
                ->with(['submissions' => fn (HasMany $query) => $query->whereBelongsTo($student, 'student')]))
            ->defaultSort('held_on', 'desc')
            ->columns([
                TextColumn::make('topic')
                    ->label('Thema')
                    ->description(fn (Lesson $record): string => $record->subject, position: 'above')
                    ->weight(FontWeight::Medium)
                    ->wrap(),
                // A Förderaufgabe was not held as a lesson, so it shows its kind instead of a date.
                TextColumn::make('held_on')
                    ->label('Stunde vom')
                    ->formatStateUsing(fn (Lesson $record): string => $record->isPracticeTask() ? 'Förderaufgabe' : $record->held_on->format('d.m.Y'))
                    ->badge(fn (Lesson $record): bool => $record->isPracticeTask())
                    ->icon(fn (Lesson $record): ?Heroicon => $record->isPracticeTask() ? Heroicon::OutlinedLifebuoy : null)
                    ->sortable()
                    ->visibleFrom('md'),
                // Status, understanding and due date share one column so the table fits a 375px phone screen.
                TextColumn::make('status')
                    ->label('Status')
                    ->state(function (Lesson $record): array {
                        $score = $record->submissions->first()?->understanding_score;

                        return array_filter([
                            match (true) {
                                $record->submissions->isNotEmpty() => 'Abgegeben',
                                $record->due_on?->isBefore(today()) ?? false => 'Überfällig',
                                default => 'Offen',
                            },
                            $score === null ? null : UnderstandingLevel::fromScore($score),
                        ]);
                    })
                    ->badge()
                    ->color(fn (string|UnderstandingLevel $state): string => match ($state) {
                        'Abgegeben' => 'success',
                        'Überfällig' => 'danger',
                        'Offen' => 'info',
                        default => $state->getColor(),
                    })
                    ->icon(fn (string|UnderstandingLevel $state): Heroicon => match ($state) {
                        'Abgegeben' => Heroicon::OutlinedCheck,
                        'Überfällig' => Heroicon::OutlinedExclamationTriangle,
                        'Offen' => Heroicon::OutlinedPencil,
                        default => $state->getIcon(),
                    })
                    ->description(fn (Lesson $record): ?string => $record->due_on ? 'Abgabe bis '.$record->due_on->format('d.m.Y') : null),
            ])
            ->recordUrl(fn (Lesson $record): string => WorkOnHomework::getUrl(['lesson' => $record]))
            ->emptyStateIcon(Heroicon::OutlinedFaceSmile)
            ->emptyStateHeading('Gerade hast du keine Hausaufgaben')
            ->emptyStateDescription('Sobald deine Lehrkraft dir etwas aufgibt, findest du es hier. Genieß die freie Zeit!');
    }

    private static function student(): ?User
    {
        /** @var User|null */
        return Filament::auth()->user();
    }
}
