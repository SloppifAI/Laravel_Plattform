<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Role;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'username', 'email', 'password', 'role', 'school_id', 'school_class_id', 'locale'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser, HasLocalePreference
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * A deleted student's id must not stay behind in the absence lists of the Klassenbuch, and the tokens of a deleted
     * account's connected apps go with it (personal_access_tokens has no foreign key, it is polymorphic; the one-time
     * codes of app_authorization_codes go by their foreign key). A new password (profile, reset by the user or an
     * administration) ends the connected apps as it ends the other browser sessions: whoever knew the old password
     * must not keep an app connected for 180 days; the person reconnects the own apps with the new one.
     */
    protected static function booted(): void
    {
        static::deleted(function (User $user): void {
            if ($user->isStudent()) {
                ClassBookEntry::forgetAbsentStudent($user);
            }

            $user->tokens()->delete();
        });

        static::updated(function (User $user): void {
            if ($user->wasChanged('password')) {
                $user->tokens()->delete();
                AppAuthorizationCode::query()->whereBelongsTo($user)->delete();
            }
        });
    }

    /**
     * The user's chosen language, or null when none was chosen (or the stored one is no longer offered). Mails and
     * notifications to the user use it as well (HasLocalePreference).
     */
    public function preferredLocale(): ?string
    {
        return array_key_exists((string) $this->locale, config('lernplattform.locale.available')) ? $this->locale : null;
    }

    /**
     * Every user may use the platform unless their school was blocked by the operator;
     * what they see inside is decided by their role.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return ! $this->school?->isBlocked();
    }

    public function isOperator(): bool
    {
        return $this->role === Role::Operator;
    }

    /**
     * Whether the user belongs to the given school. Operators belong to none.
     */
    public function isMemberOf(?School $school): bool
    {
        return $school !== null && $this->school()->is($school);
    }

    public function isAdmin(): bool
    {
        return $this->role === Role::Admin;
    }

    public function isTeacher(): bool
    {
        return $this->role === Role::Teacher;
    }

    public function isStudent(): bool
    {
        return $this->role === Role::Student;
    }

    /**
     * The school the user belongs to; null only for platform operators.
     *
     * @return BelongsTo<School, $this>
     */
    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    /**
     * The class a student belongs to.
     *
     * @return BelongsTo<SchoolClass, $this>
     */
    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class);
    }

    /**
     * Classes a teacher leads as Klassenleitung.
     *
     * @return HasMany<SchoolClass, $this>
     */
    public function ledClasses(): HasMany
    {
        return $this->hasMany(SchoolClass::class, 'teacher_id');
    }

    /**
     * Classes the administration assigned a teacher to besides those they lead. Use SchoolClass::taughtBy() for all
     * classes a teacher teaches.
     *
     * @return BelongsToMany<SchoolClass, $this>
     */
    public function taughtClasses(): BelongsToMany
    {
        return $this->belongsToMany(SchoolClass::class, 'school_class_teacher')->withTimestamps();
    }

    /**
     * Lessons a teacher has held.
     *
     * @return HasMany<Lesson, $this>
     */
    public function taughtLessons(): HasMany
    {
        return $this->hasMany(Lesson::class, 'teacher_id');
    }

    /**
     * Lessons whose homework was delegated to this student.
     *
     * @return BelongsToMany<Lesson, $this>
     */
    public function assignedLessons(): BelongsToMany
    {
        return $this->belongsToMany(Lesson::class)->withTimestamps();
    }

    /**
     * @return HasMany<Submission, $this>
     */
    public function submissions(): HasMany
    {
        return $this->hasMany(Submission::class, 'student_id');
    }

    /**
     * @return HasMany<ExplanationSession, $this>
     */
    public function explanationSessions(): HasMany
    {
        return $this->hasMany(ExplanationSession::class, 'student_id');
    }

    /**
     * @param  Builder<User>  $query
     */
    public function scopeWithRole(Builder $query, Role $role): void
    {
        $query->where('role', $role);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => Role::class,
        ];
    }
}
