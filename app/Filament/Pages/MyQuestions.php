<?php

namespace App\Filament\Pages;

use App\Filament\NavigationGroup;
use App\Models\StudentQuestion;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
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
use UnitEnum;

/**
 * "Meine Rückfragen": the questions a student sent to their teachers, with the answers. An answer counts as new until
 * the student marks it as read. The query only ever contains the student's own questions.
 */
class MyQuestions extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $slug = 'meine-rueckfragen';

    protected static ?string $title = 'Meine Rückfragen';

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Lernen;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQuestionMarkCircle;

    protected static ?int $navigationSort = 3;

    protected static string|Htmlable|null $navigationBadgeTooltip = 'Neues von deiner Lehrkraft';

    protected ?string $subheading = 'Fragen, die du deiner Lehrkraft gestellt hast, und ihre Antworten.';

    public static function canAccess(): bool
    {
        return self::student()?->isStudent() ?? false;
    }

    public static function getNavigationBadge(): ?string
    {
        $unreadCount = StudentQuestion::query()->whereBelongsTo(self::student(), 'student')->unreadAnswer()->count();

        return $unreadCount > 0 ? (string) $unreadCount : null;
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(StudentQuestion::query()->whereBelongsTo(self::student(), 'student')->with(['lesson', 'answeredBy']))
            // New answers first, then the newest question.
            ->defaultSort(fn (Builder $query): Builder => $query->orderByRaw('(answered_at IS NOT NULL AND read_at IS NULL) DESC')->latest('id'))
            ->columns([
                TextColumn::make('question')
                    ->label('Deine Frage')
                    ->description(fn (StudentQuestion $record): ?string => $record->lesson ? "{$record->lesson->subject}: {$record->lesson->topic}" : null, position: 'above')
                    ->weight(FontWeight::Medium)
                    ->extraAttributes(['class' => 'whitespace-pre-line'])
                    ->wrap(),
                TextColumn::make('answer')
                    ->label('Antwort')
                    ->state(fn (StudentQuestion $record): string => match (true) {
                        $record->isOpen() => 'Noch keine Antwort. Deine Lehrkraft sieht deine Frage in ihren Rückfragen.',
                        $record->wasMarkedDone() => 'Deine Lehrkraft hat die Frage im Unterricht besprochen oder erledigt.',
                        default => $record->answer,
                    })
                    ->description(fn (StudentQuestion $record): ?string => $record->isOpen()
                        ? null
                        : ($record->answeredBy?->name ?? 'Deine Lehrkraft').', '.$record->answered_at->format('d.m.Y'))
                    ->extraAttributes(['class' => 'whitespace-pre-line'])
                    ->wrap(),
                TextColumn::make('status')
                    ->label('Status')
                    ->state(fn (StudentQuestion $record): string => match (true) {
                        $record->isOpen() => 'Wartet auf Antwort',
                        $record->wasMarkedDone() => 'Erledigt',
                        $record->hasUnreadAnswer() => 'Neue Antwort',
                        default => 'Gelesen',
                    })
                    ->badge()
                    ->color(fn (string $state): string => $state === 'Neue Antwort' ? 'success' : 'gray')
                    ->icon(fn (string $state): Heroicon => match ($state) {
                        'Wartet auf Antwort' => Heroicon::OutlinedClock,
                        'Neue Antwort' => Heroicon::OutlinedChatBubbleLeftEllipsis,
                        'Erledigt' => Heroicon::OutlinedCheckCircle,
                        default => Heroicon::OutlinedCheck,
                    })
                    ->description(fn (StudentQuestion $record): string => 'Gefragt am '.$record->created_at->format('d.m.Y')),
            ])
            ->recordActions([
                Action::make('markAsRead')
                    ->label('Gelesen')
                    ->icon(Heroicon::OutlinedCheck)
                    ->color('gray')
                    ->authorize('markAsRead')
                    ->visible(fn (StudentQuestion $record): bool => $record->hasUnreadAnswer())
                    ->action(fn (StudentQuestion $record): bool => $record->update(['read_at' => now()])),
            ])
            ->emptyStateIcon(Heroicon::OutlinedQuestionMarkCircle)
            ->emptyStateHeading('Du hast noch keine Rückfragen gestellt')
            ->emptyStateDescription('Wenn du bei einer Hausaufgabe oder bei „Erklär’s mir“ etwas nicht verstehst, frag deine Lehrkraft. Die Antwort steht dann hier.')
            ->emptyStateActions([
                Action::make('homework')->label('Zu meinen Hausaufgaben')->icon(Heroicon::OutlinedBookOpen)->url(MyHomework::getUrl()),
                Action::make('explainIt')->label('Zu Erklär’s mir')->icon(Heroicon::OutlinedChatBubbleLeftRight)->url(ExplainIt::getUrl()),
            ]);
    }

    private static function student(): ?User
    {
        /** @var User|null */
        return Filament::auth()->user();
    }
}
