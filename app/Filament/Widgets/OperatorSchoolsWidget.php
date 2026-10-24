<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Schools\SchoolResource;
use App\Models\School;
use Filament\Widgets\Widget;

/**
 * The schools that registered most recently, with their status. Master data of the schools only, no people.
 */
class OperatorSchoolsWidget extends Widget
{
    protected static ?int $sort = 2;

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.widgets.operator-schools-widget';

    public static function canView(): bool
    {
        return auth()->user()?->isOperator() ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return [
            'schools' => School::query()->latest()->latest('id')->limit(5)->get(),
            'schoolsUrl' => class_exists(SchoolResource::class) && SchoolResource::canViewAny() ? SchoolResource::getUrl() : null,
        ];
    }
}
