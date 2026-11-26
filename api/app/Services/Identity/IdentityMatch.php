<?php

declare(strict_types=1);

namespace App\Services\Identity;

use App\Enums\EmployeeStatus;
use App\Models\Employee;
use App\Models\User;

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

    /**
     * Each match as toArray() gives it, naming only the people `$viewer` may
     * open. The resolver reads the whole organisation, archived people
     * included, and is not scoped to the viewer's part of it (#561), so a
     * department manager listing a device would otherwise see the names and
     * ids of people they cannot open. A hidden person is left out of the
     * candidates, and a match that resolved to one keeps its outcome with no
     * employee id: the screen can say nothing more about them.
     *
     * One query for every match given, not one per match.
     *
     * @template TKey of array-key
     *
     * @param  array<TKey, self>  $matches
     * @return array<TKey, array{outcome: string, employee_public_id: string|null, confidence: float, candidates: list<array{employee_public_id: string, employee_name: string, score: float, reasons: array<int, string>}>}>
     */
    public static function visibleTo(array $matches, User $viewer): array
    {
        $ids = [];
        foreach ($matches as $match) {
            if ($match->employee !== null) {
                $ids[] = (string) $match->employee->public_id;
            }
            foreach ($match->candidates as $candidate) {
                $ids[] = $candidate['employee_public_id'];
            }
        }

        $visible = [];
        if ($ids !== []) {
            Employee::withTrashed()
                ->whereIn('public_id', array_values(array_unique($ids)))
                ->get()
                ->each(function (Employee $employee) use ($viewer, &$visible) {
                    if ($viewer->canAccessEmployee($employee)) {
                        $visible[(string) $employee->public_id] = true;
                    }
                });
        }

        return array_map(function (self $match) use ($visible): array {
            $id = $match->employee?->public_id;

            return [
                'outcome' => $match->outcome,
                'employee_public_id' => $id !== null && isset($visible[$id]) ? (string) $id : null,
                'confidence' => round($match->confidence, 2),
                'candidates' => array_values(array_filter(
                    $match->candidates,
                    fn (array $candidate): bool => isset($visible[$candidate['employee_public_id']]),
                )),
            ];
        }, $matches);
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
