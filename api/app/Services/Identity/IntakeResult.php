<?php

declare(strict_types=1);

namespace App\Services\Identity;

use App\Models\Employee;
use LogicException;

/**
 * What EmployeeIntake did with a person who was presented to it.
 *
 * `created` is the only outcome in which a record was made. `unknown` is the
 * answer to ask() for someone nobody knows. Every other one names who the
 * person looks like and leaves the decision to the caller, which knows whether
 * there is a human to ask.
 */
final readonly class IntakeResult
{
    public const CREATED = 'created';

    /** Nobody the organisation knows. Nothing has been made yet. */
    public const UNKNOWN = 'unknown';

    /** A current employee, on an identifier strong enough to act on. */
    public const MATCHED = 'matched';

    /** Someone who may be this person: a weaker match, a tie, or a contradicted one. */
    public const NEEDS_REVIEW = 'needs_review';

    /** A deleted employee whose record is kept. */
    public const ARCHIVED = 'archived';

    /** Someone who has resigned, retired or been terminated. */
    public const FORMER_EMPLOYEE = 'former_employee';

    /**
     * The outcomes a person may overrule by saying "this is someone new". A
     * strong match with a current employee is not one of them.
     *
     * @var list<string>
     */
    public const CONFIRMABLE = [self::NEEDS_REVIEW, self::ARCHIVED, self::FORMER_EMPLOYEE];

    /**
     * @param  Employee|null  $employee  the record created, or the one the person matched
     */
    public function __construct(
        public string $outcome,
        public ?Employee $employee,
        public IdentityMatch $match,
    ) {}

    public function created(): bool
    {
        return $this->outcome === self::CREATED;
    }

    public function unknown(): bool
    {
        return $this->outcome === self::UNKNOWN;
    }

    /** The record, for a caller that has checked there is one. */
    public function record(): Employee
    {
        return $this->employee ?? throw new LogicException('This intake named nobody.');
    }

    /** Whether a person saying "add anyway" is enough to create the record. */
    public function confirmable(): bool
    {
        return in_array($this->outcome, self::CONFIRMABLE, true);
    }
}
