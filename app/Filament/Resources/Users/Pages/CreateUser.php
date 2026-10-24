<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use Filament\Resources\Pages\CreateRecord;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    /**
     * A school administration creates accounts in its own school, whatever the form state says.
     * Operators pick the school of a new school administration in the form; operator accounts have none.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $user = auth()->user();

        if (! $user->isOperator()) {
            $data['school_id'] = $user->school_id;
        }

        return $data;
    }
}
