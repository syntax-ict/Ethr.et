<?php

declare(strict_types=1);

namespace App\Enums;

enum RetirementType: string
{
    case MANDATORY = 'mandatory';
    case VOLUNTARY = 'voluntary';
    case EARLY = 'early';
}
