<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How much a step matters. A blocker keeps somebody from working and may be
 * snoozed but never dismissed for good; a warning is work waiting for a
 * decision or setup that is unfinished; info is worth doing and holds nobody up.
 */
enum NextStepSeverity: string
{
    case Blocker = 'blocker';
    case Warning = 'warning';
    case Info = 'info';

    /** Lower sorts first. */
    public function rank(): int
    {
        return match ($this) {
            self::Blocker => 0,
            self::Warning => 1,
            self::Info => 2,
        };
    }
}
