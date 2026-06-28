<?php

declare(strict_types=1);

namespace App\Enums;

enum AccrualType: string
{
    case MONTHLY = 'monthly';
    case ANNUAL = 'annual';
    case IMMEDIATE = 'immediate';
    case ONE_TIME = 'one_time';
}
