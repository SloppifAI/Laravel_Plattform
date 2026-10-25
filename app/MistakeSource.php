<?php

namespace App;

use Filament\Support\Contracts\HasLabel;

/**
 * Who named the mistake type of an answer. A teacher's correction wins and is never overwritten by a re-evaluation.
 * A local classifier (for example "clef") would be another case here.
 */
enum MistakeSource: string implements HasLabel
{
    case Demo = 'demo';
    case Ai = 'ai';
    case Teacher = 'teacher';

    public function getLabel(): string
    {
        return match ($this) {
            self::Demo => __('Demo-Logik'),
            self::Ai => __('KI'),
            self::Teacher => __('Lehrkraft'),
        };
    }
}
