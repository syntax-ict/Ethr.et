<?php

declare(strict_types=1);

namespace App\Services\Attendance;

use App\Models\AttendanceRecord;
use Carbon\Carbon;

/**
 * Resolves duplicate/conflicting check-ins for the same employee on the same
 * day, arriving from different sources (web, mobile, biometric, kiosk...).
 * Decision table, based on the gap between check-in times:
 *   < 1 min   → dedupe: keep the higher-confidence record (ties keep the first)
 *   1-5 min   → merge: one surviving record spanning the earliest check-in and
 *               latest check-out, keeping the higher-confidence record's identity
 *   > 5 min   → flag: both records are kept, tagged for HR review
 */
final class ConflictResolver
{
    private const DEDUPE_THRESHOLD_MINUTES = 1;

    private const MERGE_THRESHOLD_MINUTES = 5;

    public function resolve(AttendanceRecord $newRecord): ConflictResolution
    {
        if (! $newRecord->check_in) {
            return new ConflictResolution($newRecord, 'created');
        }

        $conflict = AttendanceRecord::withoutGlobalScope('tenant')
            ->where('tenant_id', $newRecord->tenant_id)
            ->where('employee_id', $newRecord->employee_id)
            ->whereDate('date', $newRecord->date->format('Y-m-d'))
            ->where('id', '!=', $newRecord->id)
            ->whereNotNull('check_in')
            ->get()
            ->sortBy(fn (AttendanceRecord $r) => abs($r->check_in->diffInSeconds($newRecord->check_in)))
            ->first();

        if (! $conflict) {
            return new ConflictResolution($newRecord, 'created');
        }

        $gapMinutes = abs($conflict->check_in->diffInMinutes($newRecord->check_in, true));

        if ($gapMinutes < self::DEDUPE_THRESHOLD_MINUTES) {
            return $this->combine($newRecord, $conflict, 'deduped');
        }

        if ($gapMinutes <= self::MERGE_THRESHOLD_MINUTES) {
            return $this->combine($newRecord, $conflict, 'merged');
        }

        return $this->flag($newRecord, $conflict);
    }

    private function combine(AttendanceRecord $newRecord, AttendanceRecord $existing, string $action): ConflictResolution
    {
        // Ties keep the existing (first-arrived) record.
        $survivor = $newRecord->confidence_score > $existing->confidence_score ? $newRecord : $existing;
        $loser = $survivor->is($newRecord) ? $existing : $newRecord;

        $survivor->update([
            'check_in' => $newRecord->check_in->lt($existing->check_in) ? $newRecord->check_in : $existing->check_in,
            'check_out' => $this->latestCheckOut($newRecord->check_out, $existing->check_out),
            'confidence_score' => max($newRecord->confidence_score, $existing->confidence_score),
            'metadata' => array_merge($survivor->metadata ?? [], [
                'conflict_action' => $action,
                'resolved_with' => $loser->public_id,
                'resolved_at' => now()->toIso8601String(),
            ]),
        ]);

        $loser->delete();

        return new ConflictResolution($survivor->fresh(), $action);
    }

    private function flag(AttendanceRecord $newRecord, AttendanceRecord $existing): ConflictResolution
    {
        $newRecord->update([
            'metadata' => array_merge($newRecord->metadata ?? [], [
                'conflict_action' => 'flagged',
                'conflict_with' => $existing->public_id,
                'flagged_at' => now()->toIso8601String(),
            ]),
        ]);

        return new ConflictResolution($newRecord->fresh(), 'flagged');
    }

    private function latestCheckOut(?Carbon $a, ?Carbon $b): ?Carbon
    {
        if (! $a) {
            return $b;
        }

        if (! $b) {
            return $a;
        }

        return $a->gt($b) ? $a : $b;
    }
}
