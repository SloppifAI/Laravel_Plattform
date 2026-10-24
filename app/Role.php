<?php

namespace App;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasDescription;
use Filament\Support\Contracts\HasLabel;

/**
 * Permission levels of the platform. What each level may do is enforced by the policies in app/Policies.
 */
enum Role: string implements HasColor, HasDescription, HasLabel
{
    case Admin = 'admin';
    case Teacher = 'teacher';
    case Student = 'student';

    public function getLabel(): string
    {
        return match ($this) {
            self::Admin => 'Administration',
            self::Teacher => 'Lehrkraft',
            self::Student => 'Schüler:in',
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::Admin => 'Verwaltet Benutzer, Klassen und das Kompetenzraster und sieht alle Auswertungen.',
            self::Teacher => 'Erstellt Unterrichtsstunden, verteilt Hausaufgaben und sieht die Ergebnisse der eigenen Schüler:innen.',
            self::Student => 'Bearbeitet zugewiesene Hausaufgaben und übt mit „Erklär’s mir“.',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Admin => 'danger',
            self::Teacher => 'primary',
            self::Student => 'success',
        };
    }
}
