<?php

namespace App\Filament;

use Filament\Support\Contracts\HasLabel;

/**
 * The groups of the sidebar. The order of the cases is the order in the menu, the label is translated per request.
 * Resources and pages name a case in $navigationGroup instead of a string, so a group is spelled in one place only.
 */
enum NavigationGroup implements HasLabel
{
    case Plattform;
    case Unterricht;
    case Lernen;
    case Verwaltung;

    public function getLabel(): string
    {
        return match ($this) {
            self::Plattform => __('Plattform'),
            self::Unterricht => __('Unterricht'),
            self::Lernen => __('Lernen'),
            self::Verwaltung => __('Verwaltung'),
        };
    }
}
