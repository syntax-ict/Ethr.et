<?php

declare(strict_types=1);

namespace App\Enums;

/** How much an announcement matters; it decides where a story sorts. */
enum AnnouncementPriority: string
{
    case LOW = 'low';

    case NORMAL = 'normal';

    case HIGH = 'high';

    case URGENT = 'urgent';

    /** Where the priority sorts, 1 first. The one place the order is written. */
    public function rank(): int
    {
        return match ($this) {
            self::URGENT => 1,
            self::HIGH => 2,
            self::NORMAL => 3,
            self::LOW => 4,
        };
    }
}
