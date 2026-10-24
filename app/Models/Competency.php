<?php

namespace App\Models;

use Database\Factories\CompetencyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One entry of the official competency framework (Referenzrahmen / Kernlehrplan) that questions are mapped to.
 * A competency without subject applies to every subject.
 */
#[Fillable(['subject', 'area', 'name', 'description'])]
class Competency extends Model
{
    /** @use HasFactory<CompetencyFactory> */
    use HasFactory;

    /**
     * @return HasMany<Question, $this>
     */
    public function questions(): HasMany
    {
        return $this->hasMany(Question::class);
    }

    /**
     * Competencies of the given subject plus the cross-subject ones.
     *
     * @param  Builder<Competency>  $query
     */
    public function scopeForSubject(Builder $query, string $subject): void
    {
        $query->where(fn (Builder $query) => $query->where('subject', $subject)->orWhereNull('subject'));
    }
}
