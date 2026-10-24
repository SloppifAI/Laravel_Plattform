<?php

namespace App\Filament\Pages;

use App\Filament\NavigationGroup;
use App\Filament\Resources\SchoolClasses\Schemas\SubstitutionForm;
use App\Filament\Resources\SchoolClasses\Tables\SubstitutionsTable;
use App\Models\ClassSubstitution;
use App\Models\SchoolClass;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use UnitEnum;

/**
 * "Vertretung": a Klassenleitung lets a teacher of the school teach their class for a period and hands over what the
 * substitute needs to know (the Übergabe-Mappe). The list shows the substitutions of the classes the user leads and
 * the classes they substitute in, each as active, upcoming or ended; a running or planned one can be ended early. The
 * administration does the same per class (SubstitutionsRelationManager).
 */
class Substitutions extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.substitutions';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Unterricht;

    protected static ?int $navigationSort = 6;

    protected static ?string $title = 'Vertretung';

    protected static ?string $slug = 'vertretung';

    public function getSubheading(): string
    {
        return 'Eine Lehrkraft übernimmt deine Klasse für einige Tage oder Wochen. Nur in diesem Zeitraum kann sie '.ClassSubstitution::grants().'.';
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->isTeacher() ?? false;
    }

    public function table(Table $table): Table
    {
        $user = auth()->user();

        return $table
            // Only within the user's own school, so a teacher who moved schools sees nothing of the old one.
            ->query(fn (): Builder => SubstitutionsTable::orderByStatus(
                ClassSubstitution::query()
                    ->with(['schoolClass', 'substitute', 'grantedBy'])
                    ->whereHas('schoolClass', fn (Builder $classes): Builder => $classes->whereNotNull('school_id')->where('school_id', $user->school_id))
                    ->where(fn (Builder $query): Builder => $query
                        ->where('substitute_id', $user->getKey())
                        ->orWhereHas('schoolClass', fn (Builder $classes): Builder => $classes->where('teacher_id', $user->getKey())))
            ))
            ->columns(SubstitutionsTable::columns(withClass: true))
            ->recordActions(SubstitutionsTable::recordActions())
            ->paginated([10, 25])
            ->emptyStateHeading('Keine Vertretungen')
            ->emptyStateDescription('Hier erscheinen Vertretungen für Klassen, die du leitest, und Klassen, die du vertrittst. Als Klassenleitung richtest du eine oben rechts ein.');
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            ...$this->handoverSheetActions(),
            Action::make('setUp')
                ->label('Vertretung einrichten')
                ->icon(Heroicon::OutlinedPlus)
                ->modalHeading('Vertretung einrichten')
                ->modalSubmitActionLabel('Einrichten')
                ->modalCancelActionLabel('Abbrechen')
                ->visible(fn (): bool => $this->ledClasses()->exists())
                ->schema([
                    Select::make('school_class_id')
                        ->label('Für welche Klasse?')
                        ->options(fn (): array => $this->ledClasses()->orderBy('name')->pluck('name', 'id')->all())
                        ->default(fn (): ?int => $this->ledClasses()->count() === 1 ? $this->ledClasses()->value('id') : null)
                        ->required()
                        ->live()
                        ->afterStateUpdated(fn (Set $set) => $set('substitute_id', null)),
                    ...SubstitutionForm::components(fn (Get $get): ?SchoolClass => $this->ledClasses()->find($get('school_class_id'))),
                ])
                ->action(function (array $data): void {
                    $schoolClass = SchoolClass::query()->findOrFail($data['school_class_id']);

                    Gate::authorize('createFor', [ClassSubstitution::class, $schoolClass]);

                    $substitution = $schoolClass->substitutions()->create([
                        'substitute_id' => $data['substitute_id'],
                        'granted_by' => auth()->id(),
                        'starts_on' => $data['starts_on'],
                        'ends_on' => $data['ends_on'],
                        'note' => filled($data['note'] ?? null) ? trim($data['note']) : null,
                    ]);

                    Notification::make()
                        ->success()
                        ->title('Vertretung eingerichtet')
                        ->body("{$substitution->substitute->name} vertritt die Klasse {$schoolClass->name} vom {$substitution->starts_on->format('d.m.Y')} bis {$substitution->ends_on->format('d.m.Y')}.")
                        ->send();
                }),
        ];
    }

    /**
     * Links to the Übergabe-Mappe of every class the teacher leads, so it can be printed before a substitution is set up.
     *
     * @return list<Action|ActionGroup>
     */
    private function handoverSheetActions(): array
    {
        $classes = $this->ledClasses()->orderBy('name')->get(['id', 'name']);
        $several = $classes->count() > 1;

        $actions = $classes
            ->map(fn (SchoolClass $schoolClass): Action => Action::make("handoverSheet{$schoolClass->getKey()}")
                ->label($several ? "Übergabe-Mappe {$schoolClass->name}" : 'Übergabe-Mappe')
                ->icon(Heroicon::OutlinedClipboardDocumentList)
                ->color('gray')
                ->url(HandoverSheet::getUrl(['schoolClass' => $schoolClass->getKey()])))
            ->all();

        return $several
            ? [ActionGroup::make($actions)->label('Übergabe-Mappe')->icon(Heroicon::OutlinedClipboardDocumentList)->button()->color('gray')]
            : $actions;
    }

    /**
     * The classes the signed-in teacher leads as Klassenleitung in their own school.
     *
     * @return Builder<SchoolClass>
     */
    private function ledClasses(): Builder
    {
        $user = auth()->user();

        return SchoolClass::query()
            ->whereNotNull('school_id')
            ->where('school_id', $user->school_id)
            ->where('teacher_id', $user->getKey());
    }
}
