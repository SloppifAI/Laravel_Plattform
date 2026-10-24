<?php

namespace App;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

/**
 * How a 0–100 understanding score reads to people. Use it everywhere a score is shown so colours stay consistent.
 */
enum UnderstandingLevel: string implements HasColor, HasIcon, HasLabel
{
    case Understood = 'understood';
    case Partly = 'partly';
    case NeedsSupport = 'needs_support';

    /**
     * Below this score a competency counts as Förderbedarf.
     */
    public const int SUPPORT_THRESHOLD = 50;

    public static function fromScore(int $score): self
    {
        return match (true) {
            $score >= 75 => self::Understood,
            $score >= self::SUPPORT_THRESHOLD => self::Partly,
            default => self::NeedsSupport,
        };
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::Understood => 'Gut verstanden',
            self::Partly => 'Teilweise verstanden',
            self::NeedsSupport => 'Förderbedarf',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Understood => 'success',
            self::Partly => 'warning',
            self::NeedsSupport => 'danger',
        };
    }

    public function getIcon(): Heroicon
    {
        return match ($this) {
            self::Understood => Heroicon::OutlinedCheckCircle,
            self::Partly => Heroicon::OutlinedAdjustmentsHorizontal,
            self::NeedsSupport => Heroicon::OutlinedLifebuoy,
        };
    }
}
