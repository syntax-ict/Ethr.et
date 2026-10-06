<?php

declare(strict_types=1);

namespace App\Enums;

enum ConflictResolutionStatus: string
{
    case PENDING = 'pending';
    case KEEP_A = 'keep_a';
    case KEEP_B = 'keep_b';
    case MERGED = 'merged';
    case DISMISSED = 'dismissed';
}
