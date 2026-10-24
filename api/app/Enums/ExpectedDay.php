<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What a day is for one employee before anyone punches: a day they are
 * expected at work, or one of the reasons they are not.
 *
 * Only a WORKDAY with no attendance can be an absence. See
 * `App\Services\Attendance\ExpectedDays`, which decides it.
 */
enum ExpectedDay: string
{
    case WORKDAY = 'workday';
    case REST_DAY = 'rest_day';
    case HOLIDAY = 'holiday';
    case ON_LEAVE = 'on_leave';
    case NOT_EMPLOYED = 'not_employed';
}
