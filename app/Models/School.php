<?php

namespace App\Models;

use Database\Factories\SchoolFactory;
use DomainException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * A school using the platform. Everything below it (classes, people, lessons) is visible only within the school.
 * Schools register themselves; the platform operator can block a school, which locks out all its users.
 */
#[Fillable(['name', 'school_number', 'school_type', 'city', 'state', 'contact_email'])]
class School extends Model
{
    /** @use HasFactory<SchoolFactory> */
    use HasFactory;

    /**
     * What the school confirms before it may switch lesson recordings on: the keys of `recording_checklist`.
     *
     * @var list<string>
     */
    public const array RECORDING_CHECKLIST = ['dsfa_done', 'personalrat_agreed', 'parents_informed', 'consent_form_approved', 'retention_set'];

    /**
     * @return HasMany<User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * @return HasMany<SchoolClass, $this>
     */
    public function schoolClasses(): HasMany
    {
        return $this->hasMany(SchoolClass::class);
    }

    public function isBlocked(): bool
    {
        return $this->blocked_at !== null;
    }

    /**
     * The account that accepted the data processing agreement for the school. Gone once that account is deleted;
     * `dpa_accepted_by_name` and `dpa_accepted_by_username` keep who it was.
     *
     * @return BelongsTo<User, $this>
     */
    public function dpaAcceptedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dpa_accepted_by');
    }

    /**
     * Every acceptance of the data processing agreement with the text as it was shown, newest first.
     *
     * @return HasMany<DpaAcceptance, $this>
     */
    public function dpaAcceptances(): HasMany
    {
        return $this->hasMany(DpaAcceptance::class)->orderByDesc('accepted_at')->orderByDesc('id');
    }

    /**
     * Whether the school accepted some version of the data processing agreement, whether it is still the current one or not.
     */
    public function hasAcceptedDpa(): bool
    {
        return $this->dpa_version !== null && $this->dpa_accepted_at !== null;
    }

    /**
     * Whether the accepted text is the one on offer now. A changed version or a changed setting behind the text (see
     * DpaAcceptance::terms()) makes the acceptance outdated until the school accepts again.
     */
    public function hasAcceptedCurrentDpa(): bool
    {
        return $this->hasAcceptedDpa()
            && $this->dpa_version === config('lernplattform.dpa.version')
            && $this->dpa_fingerprint === DpaAcceptance::fingerprint();
    }

    /**
     * Schools whose acceptance is the current text, the same test as hasAcceptedCurrentDpa().
     *
     * @param  Builder<School>  $query
     */
    public function scopeWithCurrentDpa(Builder $query): void
    {
        $query->whereNotNull('dpa_accepted_at')
            ->where('dpa_version', config('lernplattform.dpa.version'))
            ->where('dpa_fingerprint', DpaAcceptance::fingerprint());
    }

    /**
     * Schools that accepted an earlier text, so a newer one is open.
     *
     * @param  Builder<School>  $query
     */
    public function scopeWithOutdatedDpa(Builder $query): void
    {
        $query->whereNotNull('dpa_version')->where(fn (Builder $outdated) => $outdated
            ->whereNull('dpa_fingerprint')
            ->orWhere('dpa_fingerprint', '!=', DpaAcceptance::fingerprint())
            ->orWhere('dpa_version', '!=', config('lernplattform.dpa.version')));
    }

    /**
     * Schools that never accepted.
     *
     * @param  Builder<School>  $query
     */
    public function scopeWithoutDpa(Builder $query): void
    {
        $query->whereNull('dpa_version');
    }

    /**
     * Whether the school still has to accept the agreement before it may use the platform. Only a released contract
     * can be required; an outdated acceptance does not count as missing, the accepted version stays in force (Nummer 15.4).
     */
    public function mustAcceptDpa(): bool
    {
        return DpaAcceptance::isFinal() && ! $this->hasAcceptedDpa();
    }

    /**
     * Records the acceptance of the current version together with the text the school saw. The columns are not mass
     * assignable on purpose, so no form can set them. Whether the contract may be accepted at all (DpaAcceptance::isFinal())
     * is for the caller to check: the registration and "Meine Schule" offer the acceptance only for a released contract.
     */
    public function acceptDpa(User $acceptedBy): void
    {
        $now = now();
        $version = (string) config('lernplattform.dpa.version');
        $fingerprint = DpaAcceptance::fingerprint();

        DB::transaction(function () use ($acceptedBy, $now, $version, $fingerprint): void {
            $this->dpaAcceptances()->create([
                'version' => $version,
                'fingerprint' => $fingerprint,
                'content' => DpaAcceptance::render($this),
                'accepted_at' => $now,
                'accepted_by' => $acceptedBy->getKey(),
                'accepted_by_name' => $acceptedBy->name,
                'accepted_by_username' => $acceptedBy->username,
            ]);

            $this->forceFill([
                'dpa_version' => $version,
                'dpa_fingerprint' => $fingerprint,
                'dpa_accepted_at' => $now,
                'dpa_accepted_by' => $acceptedBy->getKey(),
                'dpa_accepted_by_name' => $acceptedBy->name,
                'dpa_accepted_by_username' => $acceptedBy->username,
            ])->unsetRelation('dpaAcceptedBy')->save();
        });
    }

    /**
     * Published and draft versions of the consent form for lesson recordings, newest first.
     *
     * @return HasMany<ConsentForm, $this>
     */
    public function consentForms(): HasMany
    {
        return $this->hasMany(ConsentForm::class)->orderByDesc('version');
    }

    /**
     * @return HasMany<RecordingConsent, $this>
     */
    public function recordingConsents(): HasMany
    {
        return $this->hasMany(RecordingConsent::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function recordingsEnabledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recordings_enabled_by');
    }

    /**
     * The newest published version of the consent form. Consents given for an older version are outdated.
     */
    public function currentConsentForm(): ?ConsentForm
    {
        return $this->consentForms()->whereNotNull('published_at')->first();
    }

    public function isRecordingChecklistComplete(): bool
    {
        return collect(self::RECORDING_CHECKLIST)->every(fn (string $item): bool => ($this->recording_checklist[$item] ?? false) === true);
    }

    /**
     * Whether lessons of this school may be recorded at all: switched on, not blocked, every checklist item still
     * confirmed and, once the data processing agreement is released, accepted.
     */
    public function recordingsEnabled(): bool
    {
        return $this->recordings_enabled_at !== null && ! $this->isBlocked() && $this->isRecordingChecklistComplete() && ! $this->mustAcceptDpa();
    }

    /**
     * Recordings can only be switched on with the whole checklist confirmed and a published consent form.
     */
    public function canEnableRecordings(): bool
    {
        return $this->isRecordingChecklistComplete() && $this->currentConsentForm() !== null;
    }

    /**
     * The columns are not mass assignable on purpose, so no form can set them.
     *
     * @throws DomainException when the checklist is incomplete or no consent form is published
     */
    public function enableRecordings(User $enabledBy): void
    {
        if (! $this->canEnableRecordings()) {
            throw new DomainException('Recordings need the complete checklist and a published consent form.');
        }

        $this->forceFill(['recordings_enabled_at' => now(), 'recordings_enabled_by' => $enabledBy->getKey()])->save();
    }

    public function disableRecordings(): void
    {
        $this->forceFill(['recordings_enabled_at' => null, 'recordings_enabled_by' => null])->save();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'recordings_enabled_at' => 'datetime',
            'recording_checklist' => 'array',
            'require_student_consent' => 'boolean',
            'blocked_at' => 'datetime',
            'dpa_accepted_at' => 'datetime',
        ];
    }
}
