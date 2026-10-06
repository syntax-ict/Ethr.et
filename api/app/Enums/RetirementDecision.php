<?php

declare(strict_types=1);

namespace App\Enums;

enum RetirementDecision: string
{
    case APPROVED = 'approved';
    case REJECTED = 'rejected';
}
