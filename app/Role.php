<?php

namespace App;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasDescription;
use Filament\Support\Contracts\HasLabel;

/**
 * Permission levels of the platform. What each level may do is enforced by the policies in app/Policies.
 *
 * Operators run the platform and have no school; everyone else belongs to exactly one school and only sees it.
 */
enum Role: string implements HasColor, HasDescription, HasLabel
{
    case Operator = 'operator';
    case Admin = 'admin';
    case Teacher = 'teacher';
    case Student = 'student';

    public function getLabel(): string
    {
        return match ($this) {
            self::Operator => 'Plattform-Administration',
            self::Admin => 'Schuladministration',
            self::Teacher => 'Lehrkraft',
            self::Student => 'Schüler:in',
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::Operator => 'Betreut die Plattform: verwaltet die registrierten Schulen und deren Administrationszugänge, pflegt das Kompetenzraster. Sieht keine Unterrichts- und Lerndaten.',
            self::Admin => 'Verwaltet Benutzer und Klassen der eigenen Schule und sieht alle Auswertungen dieser Schule.',
            self::Teacher => 'Erstellt Unterrichtsstunden, gibt Hausaufgaben frei und sieht die Ergebnisse der eigenen Schüler:innen.',
            self::Student => 'Bearbeitet zugewiesene Hausaufgaben und übt mit „Erklär’s mir“.',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Operator => 'gray',
            self::Admin => 'danger',
            self::Teacher => 'primary',
            self::Student => 'success',
        };
    }
}
