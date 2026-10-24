<?php

namespace App\Filament\Resources\Schools\Tables;

use App\Filament\Resources\Schools\Actions\SchoolBlockActions;
use App\Filament\Resources\Schools\Schemas\SchoolForm;
use App\Models\School;
use Filament\Actions\EditAction;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The operator sees schools and aggregate numbers only, never the people or the lessons behind them.
 */
class SchoolsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('Schule'))
                    ->weight(FontWeight::Medium)
                    ->searchable()
                    ->sortable(),
                TextColumn::make('school_number')
                    ->label(__('Schulnummer'))
                    ->fontFamily(FontFamily::Mono)
                    ->placeholder(__('Keine'))
                    ->searchable(),
                TextColumn::make('city')
                    ->label(__('Ort'))
                    ->description(fn (School $record): string => __($record->state))
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query
                        ->where('city', 'like', "%{$search}%")
                        ->orWhereIn('state', self::statesNamed($search)))
                    ->sortable(),
                TextColumn::make('school_type')
                    ->label(__('Schulform'))
                    ->formatStateUsing(fn (?string $state): ?string => filled($state) ? __($state) : $state)
                    ->placeholder(__('Keine Angabe'))
                    ->sortable(),
                TextColumn::make('dpa_version')
                    ->label(__('AVV'))
                    ->badge()
                    ->state(fn (School $record): string => match (true) {
                        $record->hasAcceptedCurrentDpa() => __('Version :version', ['version' => $record->dpa_version]),
                        $record->hasAcceptedDpa() => __('Veraltet (:version)', ['version' => $record->dpa_version]),
                        default => __('Fehlt'),
                    })
                    ->description(fn (School $record): ?string => $record->dpa_accepted_at?->format('d.m.Y'))
                    ->color(fn (School $record): string => match (true) {
                        $record->hasAcceptedCurrentDpa() => 'success',
                        $record->hasAcceptedDpa() => 'warning',
                        default => 'danger',
                    })
                    ->icon(fn (School $record): Heroicon => $record->hasAcceptedCurrentDpa() ? Heroicon::OutlinedCheckCircle : Heroicon::OutlinedExclamationTriangle)
                    ->sortable(['dpa_accepted_at']),
                TextColumn::make('users_count')
                    ->label(__('Konten'))
                    ->counts('users')
                    ->sortable(),
                TextColumn::make('school_classes_count')
                    ->label(__('Klassen'))
                    ->counts('schoolClasses')
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label(__('Registriert am'))
                    ->date('d.m.Y')
                    ->sortable(),
                TextColumn::make('blocked_at')
                    ->label(__('Status'))
                    ->badge()
                    ->state(fn (School $record): string => $record->isBlocked() ? __('Gesperrt') : __('Aktiv'))
                    ->color(fn (School $record): string => $record->isBlocked() ? 'danger' : 'success')
                    ->icon(fn (School $record): Heroicon => $record->isBlocked() ? Heroicon::OutlinedLockClosed : Heroicon::OutlinedCheckCircle)
                    ->sortable(),
            ])
            ->filters([
                TernaryFilter::make('blocked_at')
                    ->label(__('Status'))
                    ->placeholder(__('Alle'))
                    ->trueLabel(__('Gesperrt'))
                    ->falseLabel(__('Aktiv'))
                    ->nullable(),
                SelectFilter::make('dpa')
                    ->label(__('AVV'))
                    ->options([
                        'current' => __('Aktuelle Version angenommen'),
                        'outdated' => __('Veraltet, neue Version offen'),
                        'missing' => __('Fehlt'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        'current' => $query->withCurrentDpa(),
                        'outdated' => $query->withOutdatedDpa(),
                        'missing' => $query->withoutDpa(),
                        default => $query,
                    }),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                EditAction::make(),
                SchoolBlockActions::block(),
                SchoolBlockActions::unblock(),
            ]);
    }

    /**
     * The stored (German) names of the states whose name, as shown in the current language or as stored, contains the
     * search term: the table shows the translated name, so searching for it has to find the school.
     *
     * @return list<string>
     */
    private static function statesNamed(string $search): array
    {
        return array_values(array_filter(
            SchoolForm::STATES,
            fn (string $state): bool => mb_stripos($state, $search) !== false || mb_stripos(__($state), $search) !== false,
        ));
    }
}
