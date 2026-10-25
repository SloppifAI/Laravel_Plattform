<?php

namespace App\Filament\Resources\TimetableEntries\Pages;

use App\Filament\Resources\TimetableEntries\TimetableEntryResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Illuminate\Contracts\Support\Htmlable;

class ManageTimetableEntries extends ManageRecords
{
    protected static string $resource = TimetableEntryResource::class;

    public function getTitle(): string|Htmlable
    {
        return __('Stundenpläne');
    }

    public function getSubheading(): string|Htmlable|null
    {
        return __('Welche Klasse wann welches Fach hat, bei wem und in welchem Raum. Schüler:innen sehen den Plan ihrer Klasse, Lehrkräfte ihre eigenen Stunden.');
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label(__('Stunde eintragen'))
                ->modalHeading(__('Stunde in den Stundenplan eintragen')),
        ];
    }
}
