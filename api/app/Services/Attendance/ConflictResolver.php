<?php

declare(strict_types=1);

namespace App\Services\Attendance;

use App\Enums\ConflictType;
use App\Models\AttendanceConflict;
use App\Models\AttendanceRecord;
use Carbon\Carbon;

final class ConflictResolver
{
    private const DEDUPE_THRESHOLD_MINUTES = 1;

    private const MERGE_THRESHOLD_MINUTES = 5;

    private const SOURCE_PRIORITY = [
        'biometric' => 6,
        'mobile' => 5,
        'qr' => 4,
        'web' => 3,
        'manual' => 2,
        'csv' => 1,
    ];

    public function resolve(AttendanceRecord $newRecord): ConflictResolution
    {
        if (! $newRecord->check_in) {
            return new ConflictResolution($newRecord, 'created');
        }

        $existing = AttendanceRecord::withoutGlobalScope('tenant')
            ->where('tenant_id', $newRecord->tenant_id)
            ->where('employee_id', $newRecord->employee_id)
            ->whereDate('date', $newRecord->date->format('Y-m-d'))
            ->where('id', '!=', $newRecord->id)
            ->whereNotNull('check_in')
            ->where('status', '!=', 'voided')
            ->get()
            ->sortBy(fn (AttendanceRecord $r) => abs($r->check_in->diffInSeconds($newRecord->check_in)))
            ->first();

        if (! $existing) {
            return new ConflictResolution($newRecord, 'created');
        }

        $gapMinutes = abs($existing->check_in->diffInMinutes($newRecord->check_in, true));
        $sameSource = $this->sourceKey($newRecord) === $this->sourceKey($existing);

        if ($gapMinutes < self::DEDUPE_THRESHOLD_MINUTES && $sameSource) {
            return $this->combine($newRecord, $existing, 'deduped');
        }

        if ($gapMinutes < self::DEDUPE_THRESHOLD_MINUTES) {
            return $this->mergeByConfidence($newRecord, $existing, 'merged');
        }

        if ($gapMinutes <= self::MERGE_THRESHOLD_MINUTES) {
            return $this->combine($newRecord, $existing, 'merged');
        }

        return $this->flag($newRecord, $existing);
    }

    private function mergeByConfidence(AttendanceRecord $newRecord, AttendanceRecord $existing, string $action): ConflictResolution
    {
        $newPriority = $this->sourcePriority($newRecord);
        $existingPriority = $this->sourcePriority($existing);

        if ($newRecord->confidence_score === $existing->confidence_score) {
            $survivor = $newPriority >= $existingPriority ? $newRecord : $existing;
        } else {
            $survivor = $newRecord->confidence_score > $existing->confidence_score ? $newRecord : $existing;
        }

        $loser = $survivor->is($newRecord) ? $existing : $newRecord;

        return $this->performMerge($survivor, $loser, $newRecord, $existing, $action);
    }

    private function combine(AttendanceRecord $newRecord, AttendanceRecord $existing, string $action): ConflictResolution
    {
        $survivor = $newRecord->confidence_score > $existing->confidence_score ? $newRecord : $existing;
        $loser = $survivor->is($newRecord) ? $existing : $newRecord;

        return $this->performMerge($survivor, $loser, $newRecord, $existing, $action);
    }

    private function performMerge(AttendanceRecord $survivor, AttendanceRecord $loser, AttendanceRecord $newRecord, AttendanceRecord $existing, string $action): ConflictResolution
    {
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

        $loser->update([
            'status' => 'voided',
            'metadata' => array_merge($loser->metadata ?? [], [
                'voided_reason' => 'conflict_merge',
                'merged_into' => $survivor->public_id,
                'voided_at' => now()->toIso8601String(),
            ]),
        ]);

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

        AttendanceConflict::create([
            'tenant_id' => $newRecord->tenant_id,
            'employee_id' => $newRecord->employee_id,
            'record_a_id' => $existing->id,
            'record_b_id' => $newRecord->id,
            'conflict_type' => ConflictType::MULTI_SOURCE_FAR,
        ]);

        return new ConflictResolution($newRecord->fresh(), 'flagged');
    }

    private function sourcePriority(AttendanceRecord $record): int
    {
        return self::SOURCE_PRIORITY[$this->sourceKey($record)] ?? 0;
    }

    private function sourceKey(AttendanceRecord $record): string
    {
        return $record->source->value;
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
