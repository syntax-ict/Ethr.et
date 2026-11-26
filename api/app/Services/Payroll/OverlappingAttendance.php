<?php

declare(strict_types=1);

namespace App\Services\Payroll;

use App\Models\AttendanceRecord;
use Illuminate\Support\Collection;

/**
 * Which attendance records must not pay overtime because another record
 * already covers the same hours. Two records whose [check_in, check_out]
 * intervals strictly overlap are one shift recorded twice (a device punch and
 * a phone punch, a resent batch); overlap is transitive, and the longest of a
 * group pays (the lowest id on a tie). One ending exactly when the next
 * starts is not an overlap, so a split day pays both records in full.
 */
final class OverlappingAttendance
{
    /**
     * @param  Collection<int, AttendanceRecord>  $records  records with both punches, none voided
     * @return array<int, int> id of each record that pays nothing => id of the record kept for its group
     */
    public static function skipped(Collection $records): array
    {
        // Each record's punches are read once: an Eloquent date attribute is
        // parsed again on every access, and a sort reads them O(n log n) times.
        $spans = [];
        foreach ($records as $record) {
            if ($record->check_in !== null && $record->check_out !== null) {
                $spans[] = [$record->check_in->getTimestamp(), $record->check_out->getTimestamp(), $record->id];
            }
        }
        usort($spans, fn (array $a, array $b) => [$a[0], $a[2]] <=> [$b[0], $b[2]]);

        $groups = [];
        $current = [];
        $groupEnd = null;

        foreach ($spans as $span) {
            if ($current !== [] && $span[0] < $groupEnd) {
                $current[] = $span;
                $groupEnd = max($groupEnd, $span[1]);

                continue;
            }

            if (count($current) > 1) {
                $groups[] = $current;
            }
            $current = [$span];
            $groupEnd = $span[1];
        }
        if (count($current) > 1) {
            $groups[] = $current;
        }

        $skipped = [];
        foreach ($groups as $group) {
            // The longest pays; the lowest id on a tie.
            usort($group, fn (array $a, array $b) => [$b[1] - $b[0], $a[2]] <=> [$a[1] - $a[0], $b[2]]);
            $kept = array_shift($group);
            foreach ($group as $loser) {
                $skipped[$loser[2]] = $kept[2];
            }
        }

        return $skipped;
    }
}
