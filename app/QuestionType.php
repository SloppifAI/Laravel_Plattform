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
            self::Comprehension => __('Verständnis'),
            self::Application => __('Anwendung'),
            self::Reflection => __('Reflexion'),
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::Comprehension => __('Prüft, ob die Kerninhalte der Stunde verstanden wurden.'),
            self::Application => __('Überträgt das Gelernte auf eine neue Situation.'),
            self::Reflection => __('Bezieht sich auf Situationen aus der Stunde selbst und lässt sich daher kaum von einer KI beantworten.'),
        };
    }
}
