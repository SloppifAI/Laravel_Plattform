<?php

namespace App\Filament\Resources\SchoolClasses\RelationManagers;

use App\Filament\Pages\HandoverSheet;
use App\Filament\Resources\SchoolClasses\Schemas\SubstitutionForm;
use App\Filament\Resources\SchoolClasses\Tables\SubstitutionsTable;
use App\Models\ClassSubstitution;
use App\Models\SchoolClass;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Gate;

/**
 * Substitutions of the class: the administration lets a teacher of the school teach the class for a period (e.g. while
 * the Klassenleitung is ill) and can end it early. The Klassenleitung does the same on the page "Vertretung".
 */
class SubstitutionsRelationManager extends RelationManager
{
    protected static string $relationship = 'substitutions';

    protected static ?string $title = 'Vertretungen';

    protected static ?string $modelLabel = 'Vertretung';

    protected static ?string $pluralModelLabel = 'Vertretungen';

    public function table(Table $table): Table
    {
        /** @var SchoolClass $schoolClass */
        $schoolClass = $this->getOwnerRecord();

        return $table
            ->description('Eine Vertretung gilt nur im angegebenen Zeitraum: Die Lehrkraft kann dann in der Klasse '.ClassSubstitution::grants().'. Danach endet das von selbst.')
            ->modifyQueryUsing(fn ($query) => SubstitutionsTable::orderByStatus($query->with(['schoolClass', 'substitute', 'grantedBy'])))
            ->columns(SubstitutionsTable::columns(withClass: false))
            ->emptyStateHeading('Keine Vertretungen')
            ->emptyStateDescription('Lege eine Vertretung an, wenn eine Lehrkraft die Klasse für einige Tage oder Wochen übernimmt.')
            ->headerActions([
                Action::make('classHandoverSheet')
                    ->label('Übergabe-Mappe')
                    ->icon(Heroicon::OutlinedClipboardDocumentList)
                    ->color('gray')
                    ->url(HandoverSheet::getUrl(['schoolClass' => $schoolClass->getKey()]))
                    ->visible(fn (): bool => Gate::allows('viewHandoverSheet', [ClassSubstitution::class, $schoolClass])),
                CreateAction::make()
                    ->label('Vertretung einrichten')
                    ->modalHeading('Vertretung einrichten')
                    ->modalSubmitActionLabel('Einrichten')
                    ->modalCancelActionLabel('Abbrechen')
                    ->createAnotherAction(fn ($action) => $action->hidden())
                    ->schema(SubstitutionForm::components(fn (): SchoolClass => $schoolClass))
                    ->before(fn () => Gate::authorize('createFor', [ClassSubstitution::class, $schoolClass]))
                    ->mutateDataUsing(fn (array $data): array => [...$data, 'granted_by' => auth()->id()])
                    ->successNotificationTitle('Vertretung eingerichtet'),
            ])
            ->recordActions(SubstitutionsTable::recordActions());
    }
}
