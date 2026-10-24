<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\RecordingConsentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * The consent of a student's parents to lesson recordings (Art. 6 Abs. 1 lit. a, Art. 7 DSGVO), one row per student.
 * It remembers which version of the consent form was agreed to, which purposes, by whom and when, and whether it was
 * revoked. Parents reach it through a secret link; only the SHA-256 hash of that token is stored, so a database leak
 * does not leak working links. Version, purposes and date are always those of the latest declaration.
 */
#[Fillable(['school_id', 'student_id', 'consent_form_id', 'token_hash', 'token_generated_at', 'guardian_name', 'purposes', 'granted_at', 'revoked_at', 'source', 'recorded_by', 'student_confirmed_at'])]
class RecordingConsent extends Model
{
    /** @use HasFactory<RecordingConsentFactory> */
    use HasFactory;

    public const string SOURCE_DIGITAL = 'digital';

    public const string SOURCE_PAPER = 'paper';

    /** About 285 bits of randomness: not guessable, and the rate limit of the public page is only a second line of defence. */
    public const int TOKEN_LENGTH = 48;

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function findByToken(string $token): ?self
    {
        return static::query()->where('token_hash', self::hashToken($token))->first();
    }

    /**
     * The consent record of a student, not yet saved if the student has none.
     */
    public static function forStudent(User $student): self
    {
        return static::query()->firstOrNew(['student_id' => $student->getKey()], ['school_id' => $student->school_id]);
    }

    /**
     * The first name of a student as shown to parents, optionally with the initial of the last name to tell two
     * children with the same first name apart on a printed letter. Names are stored as "Vorname Nachname".
     */
    public static function firstName(User $student, bool $withInitial = false): string
    {
        $name = trim($student->name);
        $first = Str::before($name, ' ');

        return $withInitial && $first !== $name ? $first.' '.mb_substr(Str::afterLast($name, ' '), 0, 1).'.' : $first;
    }

    /**
     * @return BelongsTo<School, $this>
     */
    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    /**
     * The version of the consent form that was agreed to.
     *
     * @return BelongsTo<ConsentForm, $this>
     */
    public function consentForm(): BelongsTo
    {
        return $this->belongsTo(ConsentForm::class);
    }

    /**
     * Who recorded the latest paper declaration (consent or revocation).
     *
     * @return BelongsTo<User, $this>
     */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /**
     * Makes a new link. The old one stops working at once. The plain token is returned for the letter and kept nowhere.
     */
    public function regenerateToken(): string
    {
        $token = Str::random(self::TOKEN_LENGTH);

        $this->forceFill(['token_hash' => self::hashToken($token), 'token_generated_at' => now()])->save();

        return $token;
    }

    /**
     * @param  list<string>  $purposes
     */
    public function covers(array $purposes): bool
    {
        return array_diff($purposes, $this->purposes ?? []) === [];
    }

    /**
     * When the parents last declared their will, as consent or as revocation.
     */
    public function latestDeclarationAt(): ?CarbonInterface
    {
        return collect([$this->granted_at, $this->revoked_at])->filter()->max();
    }

    /**
     * Whether a declaration dated $at is older (by calendar day) than the one on file. The latest declaration of the
     * parents wins, so a paper slip handed in late must not override a later revocation or a later, narrower consent,
     * and a paper revocation dated before the consent on file must not cancel it.
     */
    public function predatesLatestDeclaration(CarbonInterface $at): bool
    {
        $latest = $this->latestDeclarationAt();

        return $latest !== null && $at->toDateString() < $latest->toDateString();
    }

    /**
     * Records the parents' declaration for the given form version, replacing an earlier one. A new declaration also
     * resets the student's own confirmation, because it is a new agreement.
     *
     * @param  list<string>  $purposes  only those offered by the form are kept, and the tone recording has to be one of them
     *
     * @throws InvalidArgumentException when the declaration is dated before the one on file
     */
    public function grant(ConsentForm $form, array $purposes, string $guardianName, string $source = self::SOURCE_DIGITAL, ?User $recordedBy = null, ?CarbonInterface $at = null): void
    {
        $agreed = array_values(array_intersect(array_keys(ConsentForm::PURPOSES), $purposes, $form->purposes));

        if (! in_array('audio', $agreed, true)) {
            throw new InvalidArgumentException('A consent always includes the tone recording.');
        }

        if ($at !== null && $this->predatesLatestDeclaration($at)) {
            throw new InvalidArgumentException('A declaration dated before the latest one on file cannot replace it.');
        }

        $this->forceFill([
            'consent_form_id' => $form->getKey(),
            'purposes' => $agreed,
            'guardian_name' => $guardianName,
            'granted_at' => $at ?? now(),
            'revoked_at' => null,
            'source' => $source,
            'recorded_by' => $recordedBy?->getKey(),
            'student_confirmed_at' => null,
        ])->save();
    }

    /**
     * Withdraws the consent for the future. Idempotent. A revocation recorded from paper names who recorded it.
     *
     * @throws InvalidArgumentException when the revocation is dated before the consent it revokes: the latest declaration wins
     */
    public function revoke(?User $recordedBy = null, ?CarbonInterface $at = null): void
    {
        if ($this->granted_at === null || $this->revoked_at !== null) {
            return;
        }

        if ($at !== null && $this->predatesLatestDeclaration($at)) {
            throw new InvalidArgumentException('A revocation dated before the consent on file cannot replace it.');
        }

        $this->forceFill([
            'revoked_at' => $at ?? now(),
            'recorded_by' => $recordedBy?->getKey() ?? $this->recorded_by,
        ])->save();
    }

    public function confirmByStudent(): void
    {
        $this->forceFill(['student_confirmed_at' => now()])->save();
    }

    public function withdrawByStudent(): void
    {
        $this->forceFill(['student_confirmed_at' => null])->save();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'purposes' => 'array',
            'token_generated_at' => 'datetime',
            'granted_at' => 'datetime',
            'revoked_at' => 'datetime',
            'student_confirmed_at' => 'datetime',
        ];
    }
}
