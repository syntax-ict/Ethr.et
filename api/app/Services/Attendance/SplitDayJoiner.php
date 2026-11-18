<?php

declare(strict_types=1);

namespace App\Services\Attendance;

use App\Enums\AttendanceStatus;
use App\Enums\ConflictResolutionStatus;
use App\Models\AttendanceConflict;
use App\Models\AttendanceRecord;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Payroll\PayrollPeriodLock;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Joins a working day that was recorded as two records, before 2026-11-18,
 * into the one it always was (AttendanceEngine::rejoinDay does the same for a
 * day punched now).
 *
 * A morning that ended at lunch was closed as a record of its own, and the
 * afternoon's check-in opened a second one that ConflictResolver::flag marked
 * and logged as a MULTI_SOURCE_FAR conflict. Resolving that conflict as
 * `merged` used to change no record. For a pair that is a split day it now
 * joins them; for any other pair it still changes nothing, because two
 * sources that disagree about one moment are a decision a person makes.
 *
 * A split day is exactly this: the same employee and the same work date,
 * neither record void, the earlier one closed, and the later one opened after
 * that check-out (and not closed before it opened). The earlier record is the
 * one kept, whichever the conflict calls A.
 */
final class SplitDayJoiner
{
    public const NOT_SPLIT_DAY = 'not_split_day';

    public const PAYROLL_CLOSED = 'payroll_locked';

    public const NOT_PENDING = 'not_pending';

    public const NOT_FOUND = 'not_found';

    /** How long a bulk request joins before it answers, the same twelve seconds as an import slice. */
    public const SLICE_SECONDS = 12.0;

    /** Conflicts read at a time. */
    private const CHUNK = 50;

    public function __construct(
        private readonly AttendanceEngine $engine,
        private readonly PayrollPeriodLock $lock,
        private readonly float $sliceSeconds = self::SLICE_SECONDS,
    ) {}

    /**
     * The conflict's two records as [kept, joined] when they are a split day,
     * the earlier first, or null.
     *
     * @return array{0: AttendanceRecord, 1: AttendanceRecord}|null
     */
    public function pair(AttendanceConflict $conflict): ?array
    {
        $a = $conflict->recordA;
        $b = $conflict->recordB;

        if ($a === null || $b === null || $a->is($b)) {
            return null;
        }

        return $this->order($conflict, $a, $b);
    }

    /**
     * @return array{0: AttendanceRecord, 1: AttendanceRecord}|null
     */
    private function order(AttendanceConflict $conflict, AttendanceRecord $a, AttendanceRecord $b): ?array
    {
        foreach ([$a, $b] as $record) {
            if ($record->employee_id !== $conflict->employee_id
                || $record->status === AttendanceStatus::VOIDED
                || $record->check_in === null) {
                return null;
            }
        }

        if ($a->date?->format('Y-m-d') !== $b->date?->format('Y-m-d')) {
            return null;
        }

        [$first, $second] = $a->check_in->lte($b->check_in) ? [$a, $b] : [$b, $a];

        if ($first->check_out === null || ! $second->check_in->gt($first->check_out)) {
            return null;
        }

        if ($second->check_out !== null && $second->check_out->lt($second->check_in)) {
            return null;
        }

        return [$first, $second];
    }

    /**
     * Why this conflict cannot be joined, or null when it can: it is not a
     * split day, or the day was paid in an approved payroll run.
     */
    public function refusal(AttendanceConflict $conflict): ?string
    {
        $pair = $this->pair($conflict);

        if ($pair === null) {
            return self::NOT_SPLIT_DAY;
        }

        return $this->lock->isClosed($pair[0]->employee_id, $pair[0]->date) ? self::PAYROLL_CLOSED : null;
    }

    /**
     * Resolve the conflict as `merged` by joining its two records, all or
     * nothing. Returns null when it was joined, or why it was not. The
     * conflict and both records are read again under a lock, so a second
     * request that got here first is answered NOT_PENDING.
     */
    public function join(AttendanceConflict $conflict, User $by, ?string $notes = null): ?string
    {
        return DB::transaction(function () use ($conflict, $by, $notes): ?string {
            $fresh = AttendanceConflict::query()->whereKey($conflict->getKey())->lockForUpdate()->first();

            if ($fresh === null || $fresh->resolution !== ConflictResolutionStatus::PENDING) {
                return self::NOT_PENDING;
            }

            $records = AttendanceRecord::query()
                ->whereIn('id', array_filter([$fresh->record_a_id, $fresh->record_b_id]))
                ->with('shift')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            $a = $records->get($fresh->record_a_id);
            $b = $records->get($fresh->record_b_id);

            $pair = $a !== null && $b !== null ? $this->order($fresh, $a, $b) : null;
            if ($pair === null) {
                return self::NOT_SPLIT_DAY;
            }

            [$kept, $joined] = $pair;

            if ($this->lock->isClosed($kept->employee_id, $kept->date)) {
                return self::PAYROLL_CLOSED;
            }

            $gap = (int) $kept->check_out->diffInMinutes($joined->check_in, absolute: true);

            $this->merge($kept, $joined, $fresh);

            $fresh->update([
                'resolution' => ConflictResolutionStatus::MERGED,
                'resolved_by' => $by->id,
                'resolved_at' => now(),
                'resolution_notes' => $notes,
            ]);

            AuditLog::record('attendance.split_day_joined', $kept, [
                'kept' => $kept->public_id,
                'joined' => $joined->public_id,
                'gap_minutes' => $gap,
                'conflict_id' => $fresh->public_id,
            ]);
            AuditLog::record('attendance.conflict_resolved', $fresh, [
                'resolution' => ConflictResolutionStatus::MERGED->value,
                'resolved_by' => $by->id,
            ]);

            return null;
        });
    }

    /**
     * The earlier record takes the later one's check-out. Its own check-out
     * and the later check-in become the break between them, in the entries
     * AttendanceEngine::rejoinDay writes, each with the key, the place and the
     * selfie of the punch behind it: a resend of any key is still a duplicate
     * (findByRejoinKey), the return is compared with the morning's place
     * (IntegrityInspector), and PhotoRetention finds every selfie. The later
     * record's own check-out fields become the day's.
     */
    private function merge(AttendanceRecord $kept, AttendanceRecord $joined, AttendanceConflict $conflict): void
    {
        $keptMeta = $kept->metadata ?? [];
        $joinedMeta = $joined->metadata ?? [];

        $breaks = AttendanceEngine::appendBreak((array) ($keptMeta['breaks'] ?? []), 'out', $kept->check_out, $keptMeta['checkout_idempotency_key'] ?? null, [
            'lat' => $keptMeta['checkout_latitude'] ?? null,
            'lon' => $keptMeta['checkout_longitude'] ?? null,
            'photo' => $keptMeta['checkout_photo_path'] ?? null,
        ]);
        $breaks = AttendanceEngine::appendBreak($breaks, 'in', $joined->check_in, $joined->idempotency_key, [
            'lat' => $joined->latitude !== null ? (float) $joined->latitude : null,
            'lon' => $joined->longitude !== null ? (float) $joined->longitude : null,
            'photo' => $joined->photo_path,
        ]);
        foreach ((array) ($joinedMeta['breaks'] ?? []) as $entry) {
            $breaks[] = $entry;
        }

        $metadata = array_filter($keptMeta, fn ($key) => ! str_starts_with((string) $key, 'checkout_'), ARRAY_FILTER_USE_KEY);
        $moved = array_filter($joinedMeta, fn ($key) => str_starts_with((string) $key, 'checkout_'), ARRAY_FILTER_USE_KEY);
        $metadata = [...$metadata, ...$moved];

        $metadata['breaks'] = $breaks;
        $metadata['break_photos'] = collect($breaks)->whereNotNull('photo')->count();

        if (($metadata['conflict_with'] ?? null) === $joined->public_id) {
            unset($metadata['conflict_action'], $metadata['conflict_with'], $metadata['flagged_at']);
        }

        $punches = collect([...(array) ($keptMeta['punches'] ?? []), ...(array) ($joinedMeta['punches'] ?? [])])
            ->sortBy(fn ($punch) => isset($punch['at']) ? Carbon::parse($punch['at'])->getTimestamp() : 0)
            ->values()
            ->all();
        if ($punches !== []) {
            $metadata['punches'] = array_slice($punches, -30);
        }

        $kept->update(['check_out' => $joined->check_out, 'metadata' => $metadata]);
        $this->engine->reclassify($kept);

        // The later record keeps its punch times as history; what moved to the
        // day (the check-out's fields, the check-in selfie) is not kept twice.
        $joinedRest = array_filter($joinedMeta, fn ($key) => ! str_starts_with((string) $key, 'checkout_'), ARRAY_FILTER_USE_KEY);
        $joined->update([
            'status' => AttendanceStatus::VOIDED,
            'photo_path' => null,
            'metadata' => [
                ...$joinedRest,
                'voided_reason' => 'conflict_joined',
                'conflict_id' => $conflict->public_id,
                'joined_into' => $kept->public_id,
                'voided_at' => now()->toIso8601String(),
            ],
        ]);
    }

    /**
     * Join the conflicts of `$query` for as long as the slice lasts, in order
     * of creation, after the one `$afterId` names. The first is always taken,
     * so every call makes progress. A conflict that cannot be joined stays as
     * it was, pending, and is named in `left` with its reason.
     *
     * @param  Builder<AttendanceConflict>  $query  already limited to what the caller may reach
     * @return array{joined: int, left: list<array{conflict_id: string, reason: string}>, remaining: int, last: AttendanceConflict|null}
     */
    public function joinMany(Builder $query, User $by, int $afterId = 0): array
    {
        $deadline = microtime(true) + $this->sliceSeconds;
        $joined = 0;
        $handled = 0;
        $left = [];
        $last = null;
        $cursor = $afterId;

        while (true) {
            $batch = (clone $query)->where('id', '>', $cursor)->orderBy('id')->limit(self::CHUNK)->get();

            if ($batch->isEmpty()) {
                break;
            }

            foreach ($batch as $conflict) {
                if ($handled > 0 && microtime(true) >= $deadline) {
                    break 2;
                }

                $handled++;
                $cursor = $conflict->id;
                $last = $conflict;

                $reason = $by->can('resolve', $conflict)
                    ? ($conflict->resolution !== ConflictResolutionStatus::PENDING ? self::NOT_PENDING : $this->refusal($conflict))
                    : self::NOT_FOUND;

                if ($reason === null) {
                    $reason = $this->join($conflict, $by);
                }

                if ($reason === null) {
                    $joined++;
                } else {
                    $left[] = ['conflict_id' => $conflict->public_id, 'reason' => $reason];
                }
            }
        }

        return [
            'joined' => $joined,
            'left' => $left,
            'remaining' => (clone $query)->where('id', '>', $cursor)->count(),
            'last' => $last,
        ];
    }
}
