<?php

declare(strict_types=1);

namespace Tests\Accuracy\Oracle;

/**
 * "The register marks this rule Undecided, so I give no answer." The literal
 * reading is carried only so a report can say whether a hand value happens to
 * match it; it is never asserted.
 */
final class Undecided
{
    public function __construct(
        public readonly string $rule,
        public readonly mixed $literal = null,
    ) {}
}
