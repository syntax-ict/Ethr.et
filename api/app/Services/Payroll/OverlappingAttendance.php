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
        $sorted = $records
            ->filter(fn (AttendanceRecord $r) => $r->check_in !== null && $r->check_out !== null)
            ->sort(fn (AttendanceRecord $a, AttendanceRecord $b) => [$a->check_in->getTimestamp(), $a->id] <=> [$b->check_in->getTimestamp(), $b->id])
            ->values();

        $groups = [];
        $current = [];
        $groupEnd = null;

        foreach ($sorted as $record) {
            $in = $record->check_in->getTimestamp();

            if ($current !== [] && $in < $groupEnd) {
                $current[] = $record;
                $groupEnd = max($groupEnd, $record->check_out->getTimestamp());

                continue;
            }

            if (count($current) > 1) {
                $groups[] = $current;
            }
            $current = [$record];
            $groupEnd = $record->check_out->getTimestamp();
        }
        if (count($current) > 1) {
            $groups[] = $current;
        }

        $skipped = [];
        foreach ($groups as $group) {
            usort($group, fn (AttendanceRecord $a, AttendanceRecord $b) => [
                $b->check_out->getTimestamp() - $b->check_in->getTimestamp(), $a->id,
            ] <=> [
                $a->check_out->getTimestamp() - $a->check_in->getTimestamp(), $b->id,
            ]);
            $kept = array_shift($group);
            foreach ($group as $loser) {
                $skipped[$loser->id] = $kept->id;
            }
        }

        return $skipped;
    }
}
