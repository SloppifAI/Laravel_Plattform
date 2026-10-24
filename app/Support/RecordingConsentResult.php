<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Collection;

/**
 * The outcome of RecordingConsentGate::check(): whether a recording may start, why not, and whose consent is missing.
 */
final readonly class RecordingConsentResult
{
    /**
     * @param  list<string>  $reasons  sentences for the teacher in the active language, empty when allowed
     * @param  Collection<int, User>  $studentsLackingConsent  keyed by student id
     * @param  array<int, string>  $studentReasons  why each of those students blocks the recording, keyed by student id
     */
    public function __construct(
        public bool $allowed,
        public array $reasons = [],
        public Collection $studentsLackingConsent = new Collection,
        public array $studentReasons = [],
    ) {}
}
