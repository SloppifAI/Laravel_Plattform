<?php

namespace App\Ai;

use App\MistakeSource;
use App\MistakeType;

/**
 * What a MistakeClassifier says about one answer: the kind of mistake, how sure it is (0 to 1) and where that came from.
 */
final readonly class MistakeClassification
{
    public function __construct(
        public MistakeType $type,
        public float $confidence,
        public MistakeSource $source,
    ) {}
}
