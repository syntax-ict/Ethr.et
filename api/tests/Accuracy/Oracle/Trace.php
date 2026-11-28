<?php

declare(strict_types=1);

namespace Tests\Accuracy\Oracle;

/**
 * Which register rules an answer relied on. Decided rules were applied as
 * written; Implemented rules were copied from the code, not chosen, so
 * agreement on them proves stability and not correctness. Undecided rules are
 * not recorded here: an answer carries those as Undecided values, and only
 * where their readings would give different answers.
 */
final class Trace
{
    /** @var array<string, true> */
    private array $decided = [];

    /** @var array<string, true> */
    private array $implemented = [];

    public function decided(string $rule): void
    {
        $this->decided[$rule] = true;
    }

    public function implemented(string $rule): void
    {
        $this->implemented[$rule] = true;
    }

    public function merge(self $other): void
    {
        $this->decided += $other->decided;
        $this->implemented += $other->implemented;
    }

    /** @return list<string> */
    public function decidedRules(): array
    {
        $rules = array_keys($this->decided);
        sort($rules);

        return $rules;
    }

    /** @return list<string> */
    public function implementedRules(): array
    {
        $rules = array_keys($this->implemented);
        sort($rules);

        return $rules;
    }
}
