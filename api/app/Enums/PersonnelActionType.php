<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Civil-service personnel actions — the recorded, auditable events that make up
 * an employee's employment history. Complements `EmployeeStatus` transitions
 * (hired → confirmed → retired): those move an employee along the *status* axis,
 * while these move them along the *assignment* axis (post, grade, step, unit).
 *
 * Temporary actions (acting, delegation, secondment) are recorded against the
 * employee's history but do NOT overwrite their substantive posting — the whole
 * point of "acting" is that the permanent post is unchanged.
 */
enum PersonnelActionType: string
{
    case APPOINTMENT = 'appointment';
    case PROMOTION = 'promotion';
    case DEMOTION = 'demotion';
    case TRANSFER = 'transfer';
    case REDESIGNATION = 're_designation';
    case SALARY_STEP_INCREMENT = 'salary_step_increment';
    case ACTING_ASSIGNMENT = 'acting_assignment';
    case DELEGATION = 'delegation';
    case SECONDMENT = 'secondment';
    case REASSIGNMENT = 'reassignment';

    /** Temporary actions leave the substantive posting untouched. */
    public function isTemporary(): bool
    {
        return match ($this) {
            self::ACTING_ASSIGNMENT, self::DELEGATION, self::SECONDMENT => true,
            default => false,
        };
    }
}
