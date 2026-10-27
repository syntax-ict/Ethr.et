<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What an attendance correction asks for. All three go through the same
 * request, policy and approval chain; they differ in what approving does.
 *
 *  - CORRECTION: the punches on an existing record were wrong.
 *  - MISSED_PUNCH: the employee was at work on a day with no record at all.
 *  - LATENESS: a late arrival or early departure on an existing record, and
 *    how many of its minutes payroll deducts. Changes no punch.
 */
enum CorrectionKind: string
{
    case CORRECTION = 'correction';
    case MISSED_PUNCH = 'missed_punch';
    case LATENESS = 'lateness';
}
