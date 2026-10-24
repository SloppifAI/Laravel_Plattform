<?php

namespace App;

use Filament\Support\Contracts\HasLabel;

/**
 * The difficulty a student chooses for a question: the question's own `prompt` is the Standard level, Einstieg and
 * Profi are optional variants the teacher wrote (or the AI drafted) in `prompt_basis` and `prompt_plus`.
 */
enum QuestionLevel: string implements HasLabel
{
    case Basis = 'basis';
    case Standard = 'standard';
    case Plus = 'plus';

    public function getLabel(): string
    {
        return match ($this) {
            self::Basis => __('Einstieg'),
            self::Standard => __('Standard'),
            self::Plus => __('Profi'),
        };
    }

    /**
     * The questions column holding the text of this level.
     */
    public function promptColumn(): string
    {
        return match ($this) {
            self::Basis => 'prompt_basis',
            self::Standard => 'prompt',
            self::Plus => 'prompt_plus',
        };
    }

    /**
     * The questions column caching the "Einfache Sprache" version of the text of this level.
     */
    public function simpleColumn(): string
    {
        return $this->promptColumn().'_simple';
    }
}
