<?php

namespace App\Filament\Pages;

use App\Models\User;
use Filament\Pages\Dashboard as BaseDashboard;

/**
 * The start page. Every widget decides by role whether it shows up (see app/Filament/Widgets).
 */
class Dashboard extends BaseDashboard
{
    protected static ?string $title = 'Mein Lernbereich';

    public function getSubheading(): ?string
    {
        /** @var User $user */
        $user = auth()->user();

        return match (true) {
            $user->isTeacher() => 'Dein Unterricht und der Lernstand deiner Klassen auf einen Blick.',
            $user->isAdmin() => 'Benutzer, Klassen und Unterricht der Schule im Überblick.',
            default => null,
        };
    }
}
