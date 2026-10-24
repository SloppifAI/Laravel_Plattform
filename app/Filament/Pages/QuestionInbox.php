<?php

namespace App\Filament\Pages;

use App\Filament\NavigationGroup;
use App\Models\StudentQuestion;
use App\Models\User;
use App\Support\Notify;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
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
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Throwable;
use UnitEnum;

/**
 * "Rückfragen": questions students sent about lessons the signed-in user manages (a teacher their own lessons, the
 * administration all lessons of its school; see StudentQuestion::managedBy()). Open questions come first. The page lists
 * only the question, never the student's conversation with Tuto.
 */
class QuestionInbox extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $slug = 'rueckfragen';

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Unterricht;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInbox;

    protected static ?int $navigationSort = 2;

    public static function getNavigationLabel(): string
    {
        return __('Rückfragen');
    }

    public function getTitle(): string
    {
        return __('Rückfragen');
    }

    public static function getNavigationBadgeTooltip(): string|Htmlable|null
    {
        return __('Offene Rückfragen');
    }

    public function getSubheading(): string|Htmlable|null
    {
        return __('Fragen deiner Schüler:innen zu deinen Stunden.');
    }

    public static function canAccess(): bool
    {
        $user = self::user();

        return $user !== null && ($user->isTeacher() || $user->isAdmin());
    }

    /**
     * Only teachers get the count; the administration can see all questions of the school but does not answer them by default.
     */
    public static function getNavigationBadge(): ?string
    {
        $user = self::user();

        if (! $user?->isTeacher()) {
            return null;
        }

        $openCount = StudentQuestion::query()->managedBy($user)->open()->count();

        return $openCount > 0 ? (string) $openCount : null;
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }

    public function table(Table $table): Table
    {
        return $table
            // Never lessons of colleagues or other schools: the query is limited to what the user manages.
            ->query(StudentQuestion::query()->managedBy(self::user())->with(['student', 'lesson', 'answeredBy']))
            ->defaultSort(fn (Builder $query): Builder => $query->orderByRaw('answered_at IS NULL DESC')->latest('id'))
            ->columns([
                TextColumn::make('question')
                    ->label(__('Frage'))
                    ->description(fn (StudentQuestion $record): string => "{$record->student->name} · {$record->lesson->subject}: {$record->lesson->topic}", position: 'above')
                    ->weight(FontWeight::Medium)
                    ->extraAttributes(['class' => 'whitespace-pre-line'])
                    ->wrap(),
                TextColumn::make('answer')
                    ->label(__('Antwort'))
                    ->state(fn (StudentQuestion $record): ?string => $record->wasMarkedDone() ? __('Als erledigt markiert') : $record->answer)
                    ->placeholder(__('Noch nicht beantwortet'))
                    ->description(fn (StudentQuestion $record): ?string => $record->isOpen() ? null : ($record->answeredBy?->name ?? '').' · '.$record->answered_at->format('d.m.Y'))
                    ->extraAttributes(['class' => 'whitespace-pre-line'])
                    ->wrap(),
                TextColumn::make('status')
                    ->label(__('Status'))
                    ->state(fn (StudentQuestion $record): string => $record->isOpen() ? __('Offen') : ($record->wasMarkedDone() ? __('Erledigt') : __('Beantwortet')))
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        __('Offen') => 'warning',
                        __('Beantwortet') => 'success',
                        default => 'gray',
                    })
                    ->icon(fn (string $state): Heroicon => $state === __('Offen') ? Heroicon::OutlinedClock : Heroicon::OutlinedCheck)
                    ->description(fn (StudentQuestion $record): string => __('Gefragt am :date', ['date' => $record->created_at->format('d.m.Y H:i')])),
            ])
            ->recordActions([
                Action::make('answer')
                    ->label(__('Beantworten'))
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->authorize('answer')
                    ->modalHeading(fn (StudentQuestion $record): string => __('Antwort an :name', ['name' => $record->student->name]))
                    ->modalDescription(fn (StudentQuestion $record): string => __('Frage: :question', ['question' => $record->question]))
                    ->schema([
                        Textarea::make('answer')
                            ->label(__('Deine Antwort'))
                            ->rows(5)
                            ->required()
                            ->maxLength(5000)
                            ->validationMessages([
                                'required' => __('Schreib zuerst deine Antwort.'),
                                'max' => __('Das ist zu lang. Fass dich bitte kürzer.'),
                            ]),
                    ])
                    ->modalSubmitActionLabel(__('Antwort schicken'))
                    ->modalCancelActionLabel(__('Abbrechen'))
                    ->action(function (array $data, StudentQuestion $record): void {
                        Gate::authorize('answer', $record);

                        $record->update([
                            'answer' => trim($data['answer']),
                            'answered_by' => self::user()->getKey(),
                            'answered_at' => now(),
                            'read_at' => null,
                        ]);

                        self::tellTheStudent($record);

                        Notification::make()->success()->title(__('Antwort geschickt'))->send();
                    }),
                Action::make('markDone')
                    ->label(__('Erledigt'))
                    ->icon(Heroicon::OutlinedCheck)
                    ->color('gray')
                    ->authorize('answer')
                    ->requiresConfirmation()
                    ->modalHeading(__('Frage als erledigt markieren?'))
                    ->modalDescription(__('Du schreibst keine Antwort, z. B. weil du die Frage schon im Unterricht besprochen hast. Die Schülerin oder der Schüler sieht, dass du die Frage als erledigt markiert hast.'))
                    ->modalSubmitActionLabel(__('Ja, erledigt'))
                    ->modalCancelActionLabel(__('Abbrechen'))
                    ->action(function (StudentQuestion $record): void {
                        Gate::authorize('answer', $record);

                        $record->update([
                            'answered_by' => self::user()->getKey(),
                            'answered_at' => now(),
                            'read_at' => null,
                        ]);

                        self::tellTheStudent($record);

                        Notification::make()->success()->title(__('Frage erledigt'))->send();
                    }),
            ])
            ->toolbarActions([
                // Only the open questions of the selection are closed; the table query already limits it to the user's lessons.
                BulkAction::make('markAllDone')
                    ->label(__('Als erledigt markieren'))
                    ->icon(Heroicon::OutlinedCheck)
                    ->color('gray')
                    ->authorizeIndividualRecords('answer')
                    ->requiresConfirmation()
                    ->modalHeading(__('Fragen als erledigt markieren?'))
                    ->modalDescription(__('Du schreibst keine Antwort, z. B. weil du die Fragen schon im Unterricht besprochen hast. Die Schüler:innen sehen, dass du ihre Frage als erledigt markiert hast.'))
                    ->modalSubmitActionLabel(__('Ja, erledigt'))
                    ->modalCancelActionLabel(__('Abbrechen'))
                    ->action(fn (Collection $records) => $records->each(function (StudentQuestion $record): void {
                        $record->update([
                            'answered_by' => self::user()->getKey(),
                            'answered_at' => now(),
                            'read_at' => null,
                        ]);

                        self::tellTheStudent($record);
                    }))
                    ->deselectRecordsAfterCompletion()
                    ->successNotificationTitle(__('Fragen erledigt'))
                    ->failureNotificationTitle(fn (int $successCount, int $totalCount): string => $successCount > 0
                        ? __(':successCount von :totalCount Fragen erledigt, die anderen waren schon beantwortet', ['successCount' => $successCount, 'totalCount' => $totalCount])
                        : __('Diese Fragen sind schon beantwortet')),
            ])
            ->emptyStateIcon(Heroicon::OutlinedInbox)
            ->emptyStateHeading(__('Keine Rückfragen'))
            ->emptyStateDescription(__('Wenn jemand aus deinen Klassen dir eine Frage zu einer Stunde stellt, steht sie hier.'));
    }

    /**
     * The question is already closed: a failing bell must not turn that into an error for the teacher.
     */
    private static function tellTheStudent(StudentQuestion $question): void
    {
        try {
            Notify::questionAnswered($question);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private static function user(): ?User
    {
        /** @var User|null */
        return Filament::auth()->user();
    }
}
