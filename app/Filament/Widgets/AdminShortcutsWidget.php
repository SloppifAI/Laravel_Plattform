<?php

namespace App\Filament\Widgets;

use App\Filament\NavigationGroup as AppNavigationGroup;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Filament\Widgets\Widget;

/**
 * Shortcuts to everything in the "Verwaltung" navigation group, so new admin pages show up here without code changes.
 */
class AdminShortcutsWidget extends Widget
{
    protected static ?int $sort = 2;

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.widgets.admin-shortcuts-widget';

    public static function canView(): bool
    {
        return auth()->user()?->isAdmin() ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $group = collect(Filament::getNavigation())
            ->first(fn (NavigationGroup $group): bool => $group->getLabel() === AppNavigationGroup::Verwaltung->getLabel());

        return [
            'shortcuts' => collect($group?->getItems() ?? [])
                ->filter(fn (NavigationItem $item): bool => $item->isVisible() && filled($item->getUrl()))
                ->values(),
        ];
    }
}
