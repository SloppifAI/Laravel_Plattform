<?php

namespace App\Filament\Resources\Schools\Tables;

use App\Filament\Resources\Schools\Actions\SchoolBlockActions;
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
                    ->label('Schule')
                    ->weight(FontWeight::Medium)
                    ->searchable()
                    ->sortable(),
                TextColumn::make('school_number')
                    ->label('Schulnummer')
                    ->fontFamily(FontFamily::Mono)
                    ->placeholder('Keine')
                    ->searchable(),
                TextColumn::make('city')
                    ->label('Ort')
                    ->description(fn (School $record): string => $record->state)
                    ->searchable(['city', 'state'])
                    ->sortable(),
                TextColumn::make('school_type')
                    ->label('Schulform')
                    ->placeholder('Keine Angabe')
                    ->sortable(),
                TextColumn::make('dpa_version')
                    ->label('AVV')
                    ->badge()
                    ->state(fn (School $record): string => match (true) {
                        $record->hasAcceptedCurrentDpa() => 'Version '.$record->dpa_version,
                        $record->hasAcceptedDpa() => 'Veraltet ('.$record->dpa_version.')',
                        default => 'Fehlt',
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
                    ->label('Konten')
                    ->counts('users')
                    ->sortable(),
                TextColumn::make('school_classes_count')
                    ->label('Klassen')
                    ->counts('schoolClasses')
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label('Registriert am')
                    ->date('d.m.Y')
                    ->sortable(),
                TextColumn::make('blocked_at')
                    ->label('Status')
                    ->badge()
                    ->state(fn (School $record): string => $record->isBlocked() ? 'Gesperrt' : 'Aktiv')
                    ->color(fn (School $record): string => $record->isBlocked() ? 'danger' : 'success')
                    ->icon(fn (School $record): Heroicon => $record->isBlocked() ? Heroicon::OutlinedLockClosed : Heroicon::OutlinedCheckCircle)
                    ->sortable(),
            ])
            ->filters([
                TernaryFilter::make('blocked_at')
                    ->label('Status')
                    ->placeholder('Alle')
                    ->trueLabel('Gesperrt')
                    ->falseLabel('Aktiv')
                    ->nullable(),
                SelectFilter::make('dpa')
                    ->label('AVV')
                    ->options([
                        'current' => 'Aktuelle Version angenommen',
                        'outdated' => 'Veraltet, neue Version offen',
                        'missing' => 'Fehlt',
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
}
