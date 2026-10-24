<?php

namespace App\Filament\Resources\Schools\Actions;

use App\Models\School;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;

/**
 * Locks all users of a school out of the platform, or lets them in again. The data stays untouched.
 * Filament does not authorize custom actions on its own, so both check SchoolPolicy::block before and while running.
 */
class SchoolBlockActions
{
    public static function block(): Action
    {
        return Action::make('block')
            ->label('Sperren')
            ->icon(Heroicon::OutlinedLockClosed)
            ->color('danger')
            ->authorize('block')
            ->visible(fn (School $record): bool => ! $record->isBlocked())
            ->requiresConfirmation()
            ->modalIcon(Heroicon::OutlinedLockClosed)
            ->modalHeading(fn (School $record): string => $record->name.' sperren?')
            ->modalDescription('Alle Konten dieser Schule (Schuladministration, Lehrkräfte und Schüler:innen) sind danach ausgesperrt: Sie können sich nicht mehr anmelden, und wer gerade angemeldet ist, kommt nicht mehr weiter. Die Daten bleiben erhalten. Du kannst die Schule jederzeit wieder entsperren.')
            ->modalSubmitActionLabel('Ja, Schule sperren')
            ->modalCancelActionLabel('Abbrechen')
            ->action(function (School $record): void {
                Gate::authorize('block', $record);

                $record->forceFill(['blocked_at' => now()])->save();

                Notification::make()->title('Schule gesperrt')->body($record->name.' ist jetzt gesperrt.')->success()->send();
            });
    }

    public static function unblock(): Action
    {
        return Action::make('unblock')
            ->label('Entsperren')
            ->icon(Heroicon::OutlinedLockOpen)
            ->color('success')
            ->authorize('block')
            ->visible(fn (School $record): bool => $record->isBlocked())
            ->requiresConfirmation()
            ->modalIcon(Heroicon::OutlinedLockOpen)
            ->modalHeading(fn (School $record): string => $record->name.' entsperren?')
            ->modalDescription('Alle Konten dieser Schule können sich danach wieder anmelden.')
            ->modalSubmitActionLabel('Ja, Schule entsperren')
            ->modalCancelActionLabel('Abbrechen')
            ->action(function (School $record): void {
                Gate::authorize('block', $record);

                $record->forceFill(['blocked_at' => null])->save();

                Notification::make()->title('Schule entsperrt')->body($record->name.' ist wieder freigeschaltet.')->success()->send();
            });
    }
}
