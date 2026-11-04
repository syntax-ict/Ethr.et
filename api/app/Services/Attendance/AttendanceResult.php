<?php

declare(strict_types=1);

namespace App\Services\Attendance;

use App\Models\AttendanceRecord;

/**
 * The record a punch landed on, whether it was a replay, and which punch it
 * turned out to be. `punch` matters for `auto`, where the caller did not say:
 * the shared kiosk sends `auto` because it cannot know before the code is
 * typed whether this person is arriving or leaving.
 */
final readonly class AttendanceResult
{
    /**
     * @param  'check_in'|'check_out'  $punch
     */
    public function __construct(
        public AttendanceRecord $record,
        public bool $wasDuplicate = false,
        public string $punch = 'check_in',
    ) {}
}
