<?php

declare(strict_types=1);

namespace App\Enums;

enum DisciplinaryCategory: string
{
    case MISCONDUCT = 'misconduct';
    case ABSENTEEISM = 'absenteeism';
    case INSUBORDINATION = 'insubordination';
    case NEGLIGENCE = 'negligence';
    case POLICY_VIOLATION = 'policy_violation';
    case FINANCIAL_IRREGULARITY = 'financial_irregularity';
    case HARASSMENT = 'harassment';
    case OTHER = 'other';
}
