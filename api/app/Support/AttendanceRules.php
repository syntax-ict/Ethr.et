<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Attendance timings that more than one class must agree on. Each was a
 * private constant in two places (the engine and MyShiftService, the engine
 * and ConflictResolver), so changing one would have split the rule.
 */
final class AttendanceRules
{
    /** How long after a check-in a check-out on the next day may still close it. */
    public const MAX_OPEN_HOURS = 14;

    /**
     * How long after its shift's end a record from the day before may still be
     * closed: by any check-out, for a shift that crosses midnight, and by an
     * `auto` punch (a device that does not say in or out), for any shift.
     */
    public const CLOSE_HOURS_AFTER_SHIFT_END = 6;

    /**
     * Two check-ins this close are one arrival seen twice: ConflictResolver
     * merges them, and an `auto` punch this soon after the check-in it would
     * close is answered as a duplicate rather than a check-out.
     */
    public const MERGE_WINDOW_MINUTES = 5;

    /**
     * The longest gap between a check-out and the next check-in on the same
     * work day that is still a break and not a second session: a lunch, an
     * errand, a prayer. A return within it, before the shift's end, reopens the
     * day's record (AttendanceEngine::rejoinDay); a later one stays a second
     * session beside it, flagged for review as before. Three hours covers a
     * two-session working day (08:50 to 12:05, 13:55 to 17:00) and stops short
     * of a morning job and an evening one.
     */
    public const MAX_BREAK_MINUTES = 180;

    /** How long a presence check waits for its answer, and how long the push may sit undelivered. */
    public const PRESENCE_RESPONSE_MINUTES = 15;
}
