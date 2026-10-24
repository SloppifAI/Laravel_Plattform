<?php

namespace App\Models;

use App\MessageAuthor;
use Database\Factories\ExplanationMessageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['explanation_session_id', 'author', 'content'])]
class ExplanationMessage extends Model
{
    /** @use HasFactory<ExplanationMessageFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<ExplanationSession, $this>
     */
    public function session(): BelongsTo
    {
        return $this->belongsTo(ExplanationSession::class, 'explanation_session_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'author' => MessageAuthor::class,
        ];
    }
}
