<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Competencies\CompetencyResource;
use App\Filament\Resources\Schools\SchoolResource;
use App\Filament\Resources\Users\UserResource;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\Widget;

/**
 * The operator's three jobs: look after the schools, their administration accounts and the shared competency framework.
 */
class OperatorShortcutsWidget extends Widget
{
    protected static ?int $sort = 3;

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.widgets.operator-shortcuts-widget';

    public static function canView(): bool
    {
        return auth()->user()?->isOperator() ?? false;
    }

    /**
     * The Schools resource is guarded with class_exists() so the page also works where it is not installed.
     *
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $shortcuts = [
            [SchoolResource::class, 'Schulen', 'Registrierte Schulen ansehen, Stammdaten pflegen und Schulen sperren.', Heroicon::OutlinedBuildingLibrary],
            [UserResource::class, 'Administrationszugänge', 'Zugänge der Schuladministrationen anlegen und zurücksetzen.', Heroicon::OutlinedKey],
            [CompetencyResource::class, 'Kompetenzraster', 'Der gemeinsame Referenzrahmen aller Schulen.', Heroicon::OutlinedSquares2x2],
        ];

        return [
            'shortcuts' => collect($shortcuts)
                ->filter(fn (array $shortcut): bool => class_exists($shortcut[0]) && $shortcut[0]::canAccess())
                ->map(fn (array $shortcut): array => [
                    'label' => $shortcut[1],
                    'description' => $shortcut[2],
                    'icon' => $shortcut[3],
                    'url' => $shortcut[0]::getUrl(),
                ])
                ->values(),
        ];
    }
}
