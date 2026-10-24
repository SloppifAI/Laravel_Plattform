<?php

namespace App\Filament\Resources\SchoolClasses\Pages;

use App\Filament\Resources\SchoolClasses\SchoolClassResource;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;

class EditSchoolClass extends EditRecord
{
    protected static string $resource = SchoolClassResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('regenerateJoinCode')
                ->label('Neuen Code erzeugen')
                ->icon(Heroicon::OutlinedArrowPath)
                ->color('gray')
                ->requiresConfirmation()
                ->modalHeading('Neuen Klassencode erzeugen?')
                ->modalDescription('Der alte Code funktioniert danach nicht mehr. Wer sich schon registriert hat, bleibt in der Klasse. Zeige der Klasse den neuen Code, damit sich weitere Schüler:innen anmelden können.')
                ->modalSubmitActionLabel('Neuen Code erzeugen')
                ->action(function (): void {
                    $this->getRecord()->regenerateJoinCode();
                    $this->refreshFormData(['join_code']);

                    Notification::make()
                        ->title('Neuer Klassencode: '.$this->getRecord()->join_code)
                        ->success()
                        ->send();
                }),
            DeleteAction::make(),
        ];
    }
}
