<?php

declare(strict_types=1);

namespace App\Services\Identity;

use App\Models\Employee;

/**
 * The outcome of resolving external signals against existing employees.
 *
 *  - matched:   one employee is confidently the same person → safe to link
 *  - probable:  likely the same person, but weak enough to warrant review
 *  - ambiguous: two or more candidates are near-tied → a human must decide
 *  - new:       no credible match → this is a new person
 *
 * `candidates` carries the scored shortlist (public_id, name, score, reasons)
 * so a review UI can explain the decision — and let a human pick the right
 * candidate when ambiguous — without re-running the match.
 */
final class IdentityMatch
{
    public const MATCHED = 'matched';

    public const PROBABLE = 'probable';

    public const AMBIGUOUS = 'ambiguous';

    public const NEW = 'new';

    /**
     * @param  array<int, array{employee_public_id: string, employee_name: string, score: float, reasons: array<int, string>}>  $candidates
     */
    public function __construct(
        public readonly string $outcome,
        public readonly ?Employee $employee,
        public readonly float $confidence,
        public readonly array $candidates = [],
    ) {}

    public function isMatch(): bool
    {
        return $this->outcome === self::MATCHED;
    }

    /** A match confident enough to act on automatically. */
    public function isActionable(): bool
    {
        return $this->outcome === self::MATCHED && $this->employee !== null;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'outcome' => $this->outcome,
            'employee_public_id' => $this->employee?->public_id,
            'confidence' => round($this->confidence, 2),
            'candidates' => $this->candidates,
        ];
    }
}
