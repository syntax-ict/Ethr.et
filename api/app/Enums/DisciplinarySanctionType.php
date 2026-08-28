<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Only TERMINATION is wired to a structural effect (the employee's status,
 * via the same transition machinery as `EmployeeTransitionController`,
 * applied when the case closes with the sanction still standing). The others
 * are recorded on the case but not yet applied anywhere else — a suspension
 * would need attendance-system integration and a salary deduction would need
 * payroll integration, neither of which exists yet. Recording the sanction
 * without silently pretending it enforced itself is the deliberate choice here,
 * rather than building the enforcement blind.
 *
 * (This note previously cited cost-sharing and salary-scale as comparable open
 * gaps. Both have since been built — `EmployeeCostSharing` and
 * `grade_salary_steps` — so they are no longer examples of anything. A salary
 * deduction now has a payroll deduction path it could plausibly follow.)
 */
enum DisciplinarySanctionType: string
{
    case VERBAL_WARNING = 'verbal_warning';
    case WRITTEN_WARNING = 'written_warning';
    case SUSPENSION = 'suspension';
    case DEMOTION = 'demotion';
    case SALARY_DEDUCTION = 'salary_deduction';
    case TERMINATION = 'termination';
}
