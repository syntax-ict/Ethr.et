<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Models\Employee;
use App\Services\Identity\IntakeResult;
use RuntimeException;

/**
 * Adding one employee was refused because the person looks like someone the
 * organisation already has.
 *
 * Two kinds, told apart by `code`:
 *
 *  - `employee-exists`: a current employee, on an identifier strong enough to
 *    act on (the same national ID, e-mail, or phone and name). Not overruled
 *    by the caller: the record is there to open and edit.
 *  - `employee-needs-confirmation`: someone who may be this person (a weaker
 *    match, a tie, a match that another detail contradicts, a leaver, or a
 *    deleted record). The same request with `confirm_new: true` adds them.
 *
 * bootstrap/app.php renders it as an RFC-7807 409. The people named are the
 * caller's own organisation's, and the caller holds `employee.create`.
 */
class EmployeeAlreadyExists extends RuntimeException
{
    public const EXISTS = 'employee-exists';

    public const NEEDS_CONFIRMATION = 'employee-needs-confirmation';

    /**
     * @param  list<array{public_id: string, name: string, reasons: list<string>}>  $candidates
     */
    private function __construct(
        public readonly string $kind,
        public readonly string $outcome,
        string $detail,
        public readonly array $candidates,
    ) {
        parent::__construct($detail);
    }

    public static function from(IntakeResult $result): self
    {
        $match = $result->match;
        $name = $result->employee instanceof Employee ? $result->employee->name : null;

        $candidates = array_map(static fn (array $candidate): array => [
            'public_id' => $candidate['employee_public_id'],
            'name' => $candidate['employee_name'],
            'reasons' => array_values($candidate['reasons']),
        ], array_slice($match->candidates, 0, 5));

        $detail = match ($result->outcome) {
            IntakeResult::MATCHED => __('employee.exists', ['name' => $name]),
            IntakeResult::ARCHIVED => __('employee.exists_archived', ['name' => $name]),
            IntakeResult::FORMER_EMPLOYEE => __('employee.exists_former', ['name' => $name]),
            default => $name !== null
                ? __('employee.exists_probable', ['name' => $name])
                : __('employee.exists_ambiguous'),
        };

        return new self(
            $result->confirmable() ? self::NEEDS_CONFIRMATION : self::EXISTS,
            $result->outcome,
            $detail,
            $candidates,
        );
    }
}
