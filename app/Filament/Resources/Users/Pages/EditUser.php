<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use App\Support\PersonalDataExport;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            $this->downloadDataAction(),
            DeleteAction::make(),
        ];
    }

    /**
     * Auskunft nach Art. 15 DSGVO on behalf of the person. The export only holds what the asking administration may see anyway.
     */
    private function downloadDataAction(): Action
    {
        return Action::make('downloadData')
            ->label('Datenauskunft')
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->color('gray')
            ->tooltip('Alle Daten, die zu diesem Konto gespeichert sind, als Datei (JSON)')
            ->visible(fn (): bool => auth()->user()->can('view', $this->getRecord()))
            ->action(function (): StreamedResponse {
                $record = $this->getRecord();

                Gate::authorize('view', $record);

                $json = json_encode(PersonalDataExport::for($record, auth()->user()), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                $filename = 'lernplattform-daten-'.preg_replace('/[^A-Za-z0-9._-]/', '_', $record->username).'-'.today()->toDateString().'.json';

                return response()->streamDownload(function () use ($json): void {
                    echo $json;
                }, $filename, ['Content-Type' => 'application/json; charset=UTF-8']);
            });
    }
}
