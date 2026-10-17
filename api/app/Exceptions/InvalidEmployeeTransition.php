<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\EmployeeStatus;
use RuntimeException;

/**
 * A status change `EmployeeStatus::allowedTransitions()` does not permit, such
 * as terminated to confirmed. Thrown by `EmployeeTransitionService`; each
 * caller decides how to answer it (the single transition endpoint with a 422,
 * bulk update with a failed entry for that employee).
 */
final class InvalidEmployeeTransition extends RuntimeException
{
    public function __construct(
        public readonly EmployeeStatus $from,
        public readonly EmployeeStatus $to,
    ) {
        parent::__construct(__('employee.invalid_transition', [
            'from' => $from->value,
            'to' => $to->value,
        ]));
    }
}
