<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What a public reader can filter the organisation's news by: a fixed list
 * rather than free text, so the filter is short, translated and the same for
 * every organisation.
 */
enum AnnouncementCategory: string
{
    case NEWS = 'news';

    case NOTICE = 'notice';

    case EVENT = 'event';

    case DEADLINE = 'deadline';
}
