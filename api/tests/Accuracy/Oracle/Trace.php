<?php

declare(strict_types=1);

namespace Tests\Accuracy\Oracle;

/**
 * Which register rules one answer relied on. Undecided rules are named so the
 * test can say "rule undecided" instead of pass or fail; Implemented rules are
 * named because they were copied from the code, not chosen, so agreement on
 * them proves stability and not correctness.
 */
final class Trace
{
    /** @var array<string, true> */
    private array $implemented = [];

    /** @var array<string, true> */
    private array $undecided = [];

    public function implemented(string $rule): void
    {
        $this->implemented[$rule] = true;
    }

    public function undecided(string $rule): void
    {
        $this->undecided[$rule] = true;
    }

    /** @return list<string> */
    public function implementedRules(): array
    {
        $rules = array_keys($this->implemented);
        sort($rules);

        return $rules;
    }

    /** @return list<string> */
    public function undecidedRules(): array
    {
        $rules = array_keys($this->undecided);
        sort($rules);

        return $rules;
    }
}
