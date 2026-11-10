<?php

declare(strict_types=1);

namespace App\Services\Identity;

use App\Enums\EmployeeStatus;
use App\Models\Employee;

/**
 * The outcome of resolving external signals against existing employees.
 *
 *  - matched:   one employee is confidently the same person → safe to link
 *  - probable:  likely the same person, but weak enough to warrant review
 *  - ambiguous: two or more candidates are near-tied → a human must decide
 *  - archived:  nobody current matches, and an archived employee does →
 *               restoring them is the answer, creating a second record is not
 *  - new:       no credible match → this is a new person
 *
 * `candidates` carries the scored shortlist (public_id, name, score, reasons)
 * so a review UI can explain the decision — and let a human pick the right
 * candidate when ambiguous — without re-running the match.
 *
 * `conflicts` says why a match that scored as certain is only probable: the
 * signals agree with the employee on one identifier and disagree on another
 * (`national_id_differs`, `employee_code_differs`, `name_differs`). The same
 * words are appended to the top candidate's reasons, so a screen that lists
 * reasons shows them without knowing this field exists.
 */
final class IdentityMatch
{
    public const MATCHED = 'matched';

    public const PROBABLE = 'probable';

    public const AMBIGUOUS = 'ambiguous';

    public const ARCHIVED = 'archived';

    public const NEW = 'new';

    /**
     * @param  array<int, array{employee_public_id: string, employee_name: string, score: float, reasons: array<int, string>}>  $candidates
     * @param  list<string>  $conflicts
     */
    public function __construct(
        public readonly string $outcome,
        public readonly ?Employee $employee,
        public readonly float $confidence,
        public readonly array $candidates = [],
        public readonly array $conflicts = [],
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

    /**
     * The person this resolved to has resigned, retired or been terminated.
     * Still a match — a leaver's punch has to reach the engine to be refused —
     * but not one to merge a newcomer into without a person deciding.
     */
    public function isFormerEmployee(): bool
    {
        return $this->employee !== null
            && ! $this->employee->trashed()
            && in_array($this->employee->status, EmployeeStatus::leaving(), true);
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
