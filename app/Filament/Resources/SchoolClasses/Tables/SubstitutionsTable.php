<?php

namespace App\Filament\Resources\SchoolClasses\Tables;

use App\Filament\Pages\HandoverSheet;
use App\Models\ClassSubstitution;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

/**
 * Columns and actions for lists of substitutions, shared by the class administration and the page "Vertretung".
 * Each row shows its status with a word and an icon, not by colour alone.
 */
class SubstitutionsTable
{
    /**
     * @return list<TextColumn>
     */
    public static function columns(bool $withClass): array
    {
        return [
            ...($withClass ? [
                TextColumn::make('schoolClass.name')
                    ->label('Klasse')
                    ->description(fn (ClassSubstitution $record): ?string => $record->substitute_id === auth()->id() ? 'Du vertrittst diese Klasse' : null),
            ] : []),
            TextColumn::make('substitute.name')
                ->label('Vertretung')
                ->searchable(),
            TextColumn::make('starts_on')
                ->label('Zeitraum')
                ->state(fn (ClassSubstitution $record): string => $record->ends_on->lt($record->starts_on)
                    ? $record->starts_on->format('d.m.Y').' (am ersten Tag beendet)'
                    : $record->starts_on->format('d.m.Y').' bis '.$record->ends_on->format('d.m.Y'))
                ->sortable(),
            TextColumn::make('status')
                ->label('Status')
                ->state(fn (ClassSubstitution $record): string => $record->status())
                ->badge()
                ->formatStateUsing(fn (string $state): string => match ($state) {
                    'active' => 'Aktiv',
                    'upcoming' => 'Geplant',
                    default => 'Beendet',
                })
                ->color(fn (string $state): string => match ($state) {
                    'active' => 'success',
                    'upcoming' => 'info',
                    default => 'gray',
                })
                ->icon(fn (string $state): Heroicon => match ($state) {
                    'active' => Heroicon::OutlinedCheckCircle,
                    'upcoming' => Heroicon::OutlinedCalendarDays,
                    default => Heroicon::OutlinedArchiveBox,
                }),
            TextColumn::make('note')
                ->label('Hinweis zur Übergabe')
                ->limit(120)
                ->wrap()
                ->placeholder('Kein Hinweis'),
            TextColumn::make('grantedBy.name')
                ->label('Eingerichtet von')
                ->placeholder('Konto nicht mehr vorhanden')
                ->toggleable(isToggledHiddenByDefault: true),
        ];
    }

    /**
     * Active ones first, then the upcoming ones, the ended ones last; the newest first within each group.
     *
     * @param  Builder<ClassSubstitution>  $query
     * @return Builder<ClassSubstitution>
     */
    public static function orderByStatus(Builder $query): Builder
    {
        $today = today()->toDateString();

        return $query
            ->orderByRaw('CASE WHEN DATE(ends_on) < ? THEN 2 WHEN DATE(starts_on) > ? THEN 1 ELSE 0 END', [$today, $today])
            ->orderByDesc('starts_on')
            ->orderByDesc('id');
    }

    /**
     * @return list<Action>
     */
    public static function recordActions(): array
    {
        return [
            Action::make('handoverSheet')
                ->label('Übergabe-Mappe')
                ->icon(Heroicon::OutlinedClipboardDocumentList)
                ->color('gray')
                ->url(fn (ClassSubstitution $record): string => HandoverSheet::getUrl(['schoolClass' => $record->school_class_id]))
                ->visible(fn (ClassSubstitution $record): bool => Gate::allows('viewHandoverSheet', [ClassSubstitution::class, $record->schoolClass])),
            Action::make('end')
                ->label(fn (ClassSubstitution $record): string => $record->isUpcoming() ? 'Zurückziehen' : 'Vorzeitig beenden')
                ->icon(Heroicon::OutlinedXCircle)
                ->color('gray')
                ->requiresConfirmation()
                ->modalHeading(fn (ClassSubstitution $record): string => $record->isUpcoming() ? 'Vertretung zurückziehen?' : 'Vertretung vorzeitig beenden?')
                ->modalDescription(fn (ClassSubstitution $record): string => "{$record->substitute->name} unterrichtet die Klasse {$record->schoolClass->name} danach nicht mehr und hat keinen Zugriff mehr auf ihre Lernstände, das Klassenbuch, die Einwilligungen und Tuto-Mikro.")
                ->modalSubmitActionLabel('Ja, beenden')
                ->modalCancelActionLabel('Abbrechen')
                ->visible(fn (ClassSubstitution $record): bool => ! $record->hasEnded() && Gate::allows('update', $record))
                ->action(function (ClassSubstitution $record): void {
                    Gate::authorize('update', $record);

                    $record->end();

                    Notification::make()->success()->title('Vertretung beendet')->send();
                }),
        ];
    }
}
