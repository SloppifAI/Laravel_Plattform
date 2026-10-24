<?php

namespace App;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

/**
 * Where the transcript of a lesson recording stands. Always shown as label plus icon, never by colour alone.
 */
enum TranscriptStatus: string implements HasColor, HasIcon, HasLabel
{
    /** Nothing yet: the panel is prepared, the teacher can paste text or start the transcription. */
    case None = 'none';
    /** Written live on the teacher's own device while recording. */
    case Live = 'live';
    case Pending = 'pending';
    case Processing = 'processing';
    case Done = 'done';
    case Failed = 'failed';

    public function getLabel(): string
    {
        return match ($this) {
            self::None => 'Vorbereitet',
            self::Live => 'Live mitgeschrieben',
            self::Pending => 'Ausstehend',
            self::Processing => 'Läuft',
            self::Done => 'Fertig',
            self::Failed => 'Fehlgeschlagen',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::None => 'gray',
            self::Live, self::Processing => 'info',
            self::Pending => 'warning',
            self::Done => 'success',
            self::Failed => 'danger',
        };
    }

    public function getIcon(): Heroicon
    {
        return match ($this) {
            self::None => Heroicon::OutlinedPencilSquare,
            self::Live => Heroicon::OutlinedMicrophone,
            self::Pending => Heroicon::OutlinedClock,
            self::Processing => Heroicon::OutlinedArrowPath,
            self::Done => Heroicon::OutlinedCheckCircle,
            self::Failed => Heroicon::OutlinedExclamationTriangle,
        };
    }
}
