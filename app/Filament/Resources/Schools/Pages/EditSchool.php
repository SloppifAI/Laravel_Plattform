<?php

namespace App\Filament\Resources\Schools\Pages;

use App\Filament\Resources\Schools\Actions\SchoolBlockActions;
use App\Filament\Resources\Schools\SchoolResource;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Contracts\Support\Htmlable;

class EditSchool extends EditRecord
{
    protected static string $resource = SchoolResource::class;

    protected function getHeaderActions(): array
    {
        return [
            SchoolBlockActions::block(),
            SchoolBlockActions::unblock(),
        ];
    }

    public function getSubheading(): string|Htmlable|null
    {
        $school = $this->getRecord();

        return $school->isBlocked()
            ? 'Status: Gesperrt seit '.$school->blocked_at->format('d.m.Y').'. Niemand aus dieser Schule kann sich anmelden.'
            : 'Status: Aktiv';
    }
}
