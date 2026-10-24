<?php

namespace App\Models;

use Database\Factories\DpaAcceptanceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One acceptance of the data processing agreement (Art. 28 Abs. 9 DSGVO), kept as it happened: the contract text as
 * the school saw it, the version and the fingerprint of the settings behind it, and who accepted it, as plain text
 * so that deleting the account never deletes the proof. Rows are only added, never changed.
 *
 * The text of the contract depends on the configuration (retention periods, session lifetime, whether AI and
 * transcription are switched on). `terms()` lists exactly those values and the contract view reads them from there, so
 * the fingerprint changes whenever the text would say something different.
 */
#[Fillable(['school_id', 'version', 'fingerprint', 'content', 'accepted_at', 'accepted_by', 'accepted_by_name', 'accepted_by_username'])]
class DpaAcceptance extends Model
{
    /** @use HasFactory<DpaAcceptanceFactory> */
    use HasFactory;

    /**
     * The configuration values the contract text is built from.
     *
     * @return array{keptYears: int, schoolYearStarts: string, recordingDays: int, sessionMinutes: int, resetMinutes: int, secureCookie: bool, aiEnabled: bool, transcriptionEnabled: bool}
     */
    public static function terms(): array
    {
        return [
            'keptYears' => (int) config('lernplattform.retention.school_years_kept'),
            'schoolYearStarts' => (string) config('lernplattform.retention.school_year_starts'),
            'recordingDays' => (int) config('lernplattform.recordings.keep_days'),
            'sessionMinutes' => (int) config('session.lifetime'),
            'resetMinutes' => (int) config('auth.passwords.'.config('auth.defaults.passwords').'.expire'),
            'secureCookie' => (bool) config('session.secure'),
            'aiEnabled' => (bool) config('services.anthropic.enabled'),
            'transcriptionEnabled' => (bool) config('services.transcription.enabled'),
        ];
    }

    /**
     * Identifies the text that is on offer right now: the version plus the settings behind it.
     */
    public static function fingerprint(): string
    {
        return hash('sha256', config('lernplattform.dpa.version').'|'.json_encode(self::terms()));
    }

    /**
     * Whether the operator released the contract. As long as it is a draft with open placeholders, nobody can accept it.
     */
    public static function isFinal(): bool
    {
        return (bool) config('lernplattform.dpa.final');
    }

    /**
     * The contract without the page around it. With a school, the Auftraggeber is filled in with its master data.
     */
    public static function render(?School $school = null): string
    {
        return view('filament.legal.avv-contract', ['school' => $school])->render();
    }

    /**
     * @return BelongsTo<School, $this>
     */
    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'accepted_at' => 'datetime',
        ];
    }
}
