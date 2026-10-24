<?php

namespace App;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

/**
 * Where a Klassenbuch entry stands. Always shown as label plus icon, never by colour alone.
 */
enum ClassBookStatus: string implements HasColor, HasIcon, HasLabel
{
    /** Made from a lesson and still open for changes. */
    case Draft = 'draft';
    /** Locked by the teacher; only the school administration reopens it. */
    case Confirmed = 'confirmed';

    public function getLabel(): string
    {
        return match ($this) {
            self::Draft => __('Entwurf'),
            self::Confirmed => __('Bestätigt'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Draft => 'warning',
            self::Confirmed => 'success',
        };
    }

    public function getIcon(): Heroicon
    {
        return match ($this) {
            self::Draft => Heroicon::OutlinedPencilSquare,
            self::Confirmed => Heroicon::OutlinedLockClosed,
        };
    }
}
