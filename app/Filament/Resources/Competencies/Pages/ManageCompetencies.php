<?php

namespace App\Filament\Resources\Competencies\Pages;

use App\Filament\Resources\Competencies\CompetencyResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageCompetencies extends ManageRecords
{
    protected static string $resource = CompetencyResource::class;

    protected static ?string $title = 'Kompetenzraster';

    protected ?string $subheading = 'Die Kompetenzen des Referenzrahmens, denen Hausaufgabenfragen zugeordnet werden.';

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
