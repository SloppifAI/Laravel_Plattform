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
                    ->label(__('Klasse'))
                    ->description(fn (ClassSubstitution $record): ?string => $record->substitute_id === auth()->id() ? __('Du vertrittst diese Klasse') : null),
            ] : []),
            TextColumn::make('substitute.name')
                ->label(__('Vertretung'))
                ->searchable(),
            TextColumn::make('starts_on')
                ->label(__('Zeitraum'))
                ->state(fn (ClassSubstitution $record): string => $record->ends_on->lt($record->starts_on)
                    ? __(':start (am ersten Tag beendet)', ['start' => $record->starts_on->format('d.m.Y')])
                    : __(':start bis :end', ['start' => $record->starts_on->format('d.m.Y'), 'end' => $record->ends_on->format('d.m.Y')]))
                ->sortable(),
            TextColumn::make('status')
                ->label(__('Status'))
                ->state(fn (ClassSubstitution $record): string => $record->status())
                ->badge()
                ->formatStateUsing(fn (string $state): string => match ($state) {
                    'active' => __('Aktiv'),
                    'upcoming' => __('Geplant'),
                    default => __('Beendet'),
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
                ->label(__('Hinweis zur Übergabe'))
                ->limit(120)
                ->wrap()
                ->placeholder(__('Kein Hinweis')),
            TextColumn::make('grantedBy.name')
                ->label(__('Eingerichtet von'))
                ->placeholder(__('Konto nicht mehr vorhanden'))
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
                ->label(__('Übergabe-Mappe'))
                ->icon(Heroicon::OutlinedClipboardDocumentList)
                ->color('gray')
                ->url(fn (ClassSubstitution $record): string => HandoverSheet::getUrl(['schoolClass' => $record->school_class_id]))
                ->visible(fn (ClassSubstitution $record): bool => Gate::allows('viewHandoverSheet', [ClassSubstitution::class, $record->schoolClass])),
            Action::make('end')
                ->label(fn (ClassSubstitution $record): string => $record->isUpcoming() ? __('Zurückziehen') : __('Vorzeitig beenden'))
                ->icon(Heroicon::OutlinedXCircle)
                ->color('gray')
                ->requiresConfirmation()
                ->modalHeading(fn (ClassSubstitution $record): string => $record->isUpcoming() ? __('Vertretung zurückziehen?') : __('Vertretung vorzeitig beenden?'))
                ->modalDescription(fn (ClassSubstitution $record): string => __(':teacher unterrichtet die Klasse :class danach nicht mehr und hat keinen Zugriff mehr auf ihre Lernstände, das Klassenbuch, die Einwilligungen und Tuto-Mikro.', ['teacher' => $record->substitute->name, 'class' => $record->schoolClass->name]))
                ->modalSubmitActionLabel(__('Ja, beenden'))
                ->modalCancelActionLabel(__('Abbrechen'))
                ->visible(fn (ClassSubstitution $record): bool => ! $record->hasEnded() && Gate::allows('update', $record))
                ->action(function (ClassSubstitution $record): void {
                    Gate::authorize('update', $record);

                    $record->end();

                    Notification::make()->success()->title(__('Vertretung beendet'))->send();
                }),
        ];
    }
}
