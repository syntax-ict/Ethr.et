<?php

declare(strict_types=1);

namespace App\Services\Attendance;

use App\Models\AttendanceRecord;
use Carbon\Carbon;

final class ConflictResolver
{
    /**
     * Check for and resolve conflicts when a new record arrives.
     * Returns: 'created', 'merged', or 'flagged'.
     */
    public function resolve(AttendanceRecord $newRecord): string
    {
        $dateStr = $newRecord->date->format('Y-m-d');

        $existing = AttendanceRecord::withoutGlobalScope('tenant')
            ->where('tenant_id', $newRecord->tenant_id)
            ->where('employee_id', $newRecord->employee_id)
            ->whereDate('date', $dateStr)
            ->where('id', '!=', $newRecord->id)
            ->whereNotNull('check_in')
            ->get();

        if ($existing->isEmpty()) {
            return 'created';
        }

        foreach ($existing as $record) {
            if ($this->isSameMinute($newRecord->check_in, $record->check_in)) {
                if ($newRecord->confidence_score > $record->confidence_score) {
                    $record->delete();

                    return 'merged';
                }

                $newRecord->delete();

                return 'merged';
            }

            if ($this->hasOverlap($newRecord, $record)) {
                $newRecord->update([
                    'metadata' => array_merge($newRecord->metadata ?? [], [
                        'conflict_with' => $record->public_id,
                        'flagged_at' => now()->toIso8601String(),
                    ]),
                ]);

                return 'flagged';
            }
        }

        return 'created';
    }

    private function isSameMinute(?Carbon $a, ?Carbon $b): bool
    {
        if (! $a || ! $b) {
            return false;
        }

        return $a->format('Y-m-d H:i') === $b->format('Y-m-d H:i');
    }

    private function hasOverlap(AttendanceRecord $a, AttendanceRecord $b): bool
    {
        if (! $a->check_in || ! $b->check_in) {
            return false;
        }

        $aStart = $a->check_in;
        $aEnd = $a->check_out ?? $aStart->copy()->addHours(8);
        $bStart = $b->check_in;
        $bEnd = $b->check_out ?? $bStart->copy()->addHours(8);

        return $aStart->lt($bEnd) && $aEnd->gt($bStart);
    }
}
