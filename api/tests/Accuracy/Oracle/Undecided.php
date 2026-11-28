<?php

declare(strict_types=1);

namespace Tests\Accuracy\Oracle;

/**
 * "The readings of an Undecided rule give different answers for this input, so
 * I give none." The baseline reading is carried only so a report can say
 * whether a hand value happens to match it; it is never asserted.
 */
final class Undecided
{
    /** @param list<string> $rules */
    public function __construct(
        public readonly array $rules,
        public readonly mixed $literal = null,
    ) {}

    public function label(): string
    {
        return implode('+', $this->rules);
    }
}
