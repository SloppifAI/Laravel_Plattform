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
        /** @var User|null $user */
        $user = auth()->user();

        return match (true) {
            (bool) $user?->isTeacher() => __('Mein Unterricht'),
            (bool) $user?->isAdmin() => __('Schulübersicht'),
            (bool) $user?->isOperator() => __('Plattformübersicht'),
            default => __('Mein Lernbereich'),
        };
    }

    public function getTitle(): string|Htmlable
    {
        return static::getNavigationLabel();
    }

    /**
     * Teachers are greeted by name like students; students get theirs in the widget below the heading.
     */
    public function getHeading(): string|Htmlable
    {
        /** @var User $user */
        $user = auth()->user();

        return $user->isTeacher() ? __('Hallo :name!', ['name' => $user->name]) : $this->getTitle();
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
