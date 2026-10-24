<?php

namespace App\Filament\Resources\SchoolClasses\Pages;

use App\Filament\Resources\SchoolClasses\SchoolClassResource;
use Filament\Resources\Pages\CreateRecord;

class CreateSchoolClass extends CreateRecord
{
    protected static string $resource = SchoolClassResource::class;

    /**
     * A new class belongs to the school of the administrator who creates it.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['school_id'] = auth()->user()->school_id;

        return $data;
    }
}
