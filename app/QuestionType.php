<?php

namespace App;

use Filament\Support\Contracts\HasDescription;
use Filament\Support\Contracts\HasLabel;

enum QuestionType: string implements HasDescription, HasLabel
{
    case Comprehension = 'comprehension';
    case Application = 'application';
    case Reflection = 'reflection';

    public function getLabel(): string
    {
        return match ($this) {
            self::Comprehension => 'Verständnis',
            self::Application => 'Anwendung',
            self::Reflection => 'Reflexion',
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::Comprehension => 'Prüft, ob die Kerninhalte der Stunde verstanden wurden.',
            self::Application => 'Überträgt das Gelernte auf eine neue Situation.',
            self::Reflection => 'Bezieht sich auf Situationen aus der Stunde selbst und lässt sich daher kaum von einer KI beantworten.',
        };
    }
}
