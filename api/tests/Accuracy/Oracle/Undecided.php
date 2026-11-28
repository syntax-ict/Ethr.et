<?php

declare(strict_types=1);

namespace Tests\Accuracy\Oracle;

/**
 * "The readings of an Undecided rule give different answers for this input, so
 * I give none." The baseline reading is carried only so a report can say
 * whether a hand value happens to match it; `values` holds what every reading
 * gives, so a comparer can also reject a value that matches none of them.
 */
final class Undecided
{
    /** @var list<mixed> every distinct value the field takes across the readings, baseline first */
    public readonly array $values;

    /**
     * @param  list<string>  $rules
     * @param  list<mixed>|null  $values  null: only the baseline
     */
    public function __construct(
        public readonly array $rules,
        public readonly mixed $literal = null,
        ?array $values = null,
    ) {
        $this->values = $values ?? [$literal];
    }

    public function label(): string
    {
        return implode('+', $this->rules);
    }
}
