<?php

declare(strict_types=1);

namespace App\Enums;

enum ContractStatus: string
{
    case ACTIVE = 'active';
    case RENEWED = 'renewed';
    case EXPIRED = 'expired';
    case TERMINATED_EARLY = 'terminated_early';

    public function isTerminal(): bool
    {
        return $this !== self::ACTIVE;
    }
}
