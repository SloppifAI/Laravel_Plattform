<?php

namespace App;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * How a student rates themselves after revealing the solution in "Heute wiederholen". Only for practising: a rating
 * moves the question between the Leitner boxes (ReviewSchedule) and never counts as a score. No rating is red, because
 * not knowing something is part of learning.
 */
enum ReviewRating: string implements HasColor, HasLabel
{
    case Knew = 'knew';
    case Hard = 'hard';
    case Forgot = 'forgot';

    public function getLabel(): string
    {
        return match ($this) {
            self::Knew => __('Sofort gewusst'),
            self::Hard => __('Mit Mühe'),
            self::Forgot => __('Nicht gewusst'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Knew => 'success',
            self::Hard => 'warning',
            self::Forgot => 'gray',
        };
    }

    /**
     * The review_items counter this rating adds one to.
     */
    public function counterColumn(): string
    {
        return $this->value.'_count';
    }
}
