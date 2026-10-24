<?php

namespace App\Filament\Pages;

use App\Models\User;
use Filament\Pages\Dashboard as BaseDashboard;
use Illuminate\Contracts\Support\Htmlable;

/**
 * The start page. Every widget decides by role whether it shows up (see app/Filament/Widgets).
 */
class Dashboard extends BaseDashboard
{
    public static function getNavigationLabel(): string|Htmlable
    {
        return __('Mein Lernbereich');
    }

    public function getTitle(): string|Htmlable
    {
        return __('Mein Lernbereich');
    }

    public function getSubheading(): ?string
    {
        /** @var User $user */
        $user = auth()->user();

        return match (true) {
            $user->isTeacher() => __('Dein Unterricht und der Lernstand deiner Klassen auf einen Blick.'),
            $user->isAdmin() => __('Benutzer, Klassen und Unterricht der Schule im Überblick.'),
            $user->isOperator() => __('Überblick über alle Schulen der Plattform.'),
            default => null,
        };
    }
}
