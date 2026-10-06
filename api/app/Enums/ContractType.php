<?php

declare(strict_types=1);

namespace App\Enums;

enum ContractType: string
{
    case PROBATION = 'probation';
    case FIXED_TERM = 'fixed_term';
    case PERMANENT = 'permanent';
    case CASUAL = 'casual';
    case CONSULTANCY = 'consultancy';

    /** Every type except PERMANENT must carry an end date. */
    public function requiresEndDate(): bool
    {
        return $this !== self::PERMANENT;
    }
}
