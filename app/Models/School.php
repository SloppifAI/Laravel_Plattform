<?php

namespace App\Models;

use Database\Factories\SchoolFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'blocked_at' => 'datetime',
        ];
    }
}
