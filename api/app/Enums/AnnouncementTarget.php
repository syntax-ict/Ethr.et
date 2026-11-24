<?php

declare(strict_types=1);

namespace App\Enums;

/** Who an announcement is addressed to: everyone, or one department or branch. */
enum AnnouncementTarget: string
{
    case ALL = 'all';

    case DEPARTMENT = 'department';

    case BRANCH = 'branch';
}
