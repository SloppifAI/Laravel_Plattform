<?php

namespace App;

use App\Models\ConsentForm;
use App\Models\RecordingConsent;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

/**
 * Where a student's consent to lesson recordings stands. Always shown as label plus icon, never by colour alone.
 */
enum ConsentStatus: string implements HasColor, HasIcon, HasLabel
{
    /** Parents agreed to every purpose of the current form (and the student confirmed, where the school asks for it). */
    case Granted = 'granted';
    /** Parents agreed to some of the purposes only. */
    case Partial = 'partial';
    /** No consent has been given yet. */
    case Missing = 'missing';
    case Revoked = 'revoked';
    /** Given for an older version of the consent form than the current one. */
    case Outdated = 'outdated';
    case StudentPending = 'student_pending';

    /**
     * @param  list<string>|null  $purposes  what has to be covered; the purposes of the current form when null
     */
    public static function for(?RecordingConsent $consent, ?ConsentForm $currentForm, bool $requiresStudentConfirmation, ?array $purposes = null): self
    {
        return match (true) {
            $consent === null || $consent->granted_at === null => self::Missing,
            $consent->revoked_at !== null => self::Revoked,
            $currentForm === null || (int) $consent->consent_form_id !== (int) $currentForm->getKey() => self::Outdated,
            ! $consent->covers($purposes ?? $currentForm->purposes) => self::Partial,
            $requiresStudentConfirmation && $consent->student_confirmed_at === null => self::StudentPending,
            default => self::Granted,
        };
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::Granted => __('Erteilt'),
            self::Partial => __('Teilweise'),
            self::Missing => __('Fehlt'),
            self::Revoked => __('Widerrufen'),
            self::Outdated => __('Veraltet'),
            self::StudentPending => __('Schüler:in-Bestätigung fehlt'),
        };
    }

    /**
     * Why a recording is blocked for a student in this state, as a clause after the student's name.
     */
    public function getReason(): string
    {
        return match ($this) {
            self::Granted => __('Einwilligung liegt vor'),
            self::Partial => __('Die Einwilligung deckt nicht alle nötigen Zwecke ab'),
            self::Missing => __('Es liegt keine Einwilligung der Erziehungsberechtigten vor'),
            self::Revoked => __('Die Einwilligung wurde widerrufen'),
            self::Outdated => __('Die Einwilligung gilt nur für eine ältere Fassung der Erklärung'),
            self::StudentPending => __('Die eigene Bestätigung der Schüler:in fehlt'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Granted => 'success',
            self::Partial, self::Outdated => 'warning',
            self::Missing => 'gray',
            self::Revoked => 'danger',
            self::StudentPending => 'info',
        };
    }

    public function getIcon(): Heroicon
    {
        return match ($this) {
            self::Granted => Heroicon::OutlinedCheckCircle,
            self::Partial => Heroicon::OutlinedExclamationTriangle,
            self::Missing => Heroicon::OutlinedQuestionMarkCircle,
            self::Revoked => Heroicon::OutlinedXCircle,
            self::Outdated => Heroicon::OutlinedArrowPath,
            self::StudentPending => Heroicon::OutlinedClock,
        };
    }
}
