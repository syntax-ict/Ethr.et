<?php

declare(strict_types=1);

namespace App\Enums;

enum DisciplinaryAppealStatus: string
{
    case PENDING = 'pending';
    case UPHELD = 'upheld';
    case DENIED = 'denied';
}
