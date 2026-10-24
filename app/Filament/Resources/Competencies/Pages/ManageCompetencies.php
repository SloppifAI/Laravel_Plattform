<?php

namespace App\Filament\Resources\Competencies\Pages;

use App\Filament\Resources\Competencies\CompetencyResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Illuminate\Contracts\Support\Htmlable;

class ManageCompetencies extends ManageRecords
{
    protected static string $resource = CompetencyResource::class;

    public function getTitle(): string|Htmlable
    {
        return __('Kompetenzraster');
    }

    public function getSubheading(): string|Htmlable|null
    {
        return __('Die Kompetenzen des Referenzrahmens, denen Hausaufgabenfragen zugeordnet werden.');
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
