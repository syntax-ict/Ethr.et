<?php

declare(strict_types=1);

namespace App\Enums;

enum ProfileUpdateStatus: string
{
    case PENDING = 'pending';
    case APPROVED = 'approved';
    case REJECTED = 'rejected';

    /**
     * Retracted by the employee before anyone reviewed it.
     *
     * Distinct from REJECTED so HR's queue metrics do not count an employee
     * changing their mind as a decision a reviewer made.
     */
    case WITHDRAWN = 'withdrawn';
}
