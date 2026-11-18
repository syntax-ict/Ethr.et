<?php

declare(strict_types=1);

namespace App\Services\Attendance;

use App\Enums\AttendanceStatus;
use App\Models\AttendanceIntegrityFlag;
use App\Models\AttendancePresenceCheck;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSetting;
use App\Models\Employee;
use App\Models\User;
use App\Services\Push\WebPushSender;
use App\Support\AttendanceRules;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Presence checks: a few unannounced "are you at work?" pushes during a shift,
 * answered from the phone with its position.
 *
 * The times are derived, never stored. They come from the record's id under
 * the app key, so the same record always has the same times, the phone cannot
 * work them out, and nothing needs a schedule table. A row exists only once a
 * push has gone out, and holds what was answered.
 *
 * Sent from the five-minute punch-reminder pass (PushPunchRemindersCommand),
 * so a check goes out up to a tick late, never early. It is never sent during
 * the middle of the shift (where lunch usually falls), while a break is open,
 * or to anyone with no place to be measured against.
 */
final class PresenceChecks
{
    /** Minutes kept clear after the start of the usable window and before the shift's end. */
    private const MARGIN_MINUTES = 30;

    /** Padding added to half the shift's break when keeping the middle of the day clear. */
    private const MIDDLE_BAND_PADDING_MINUTES = 15;

    /** Fewer usable minutes than this and the shift is too short to check. */
    private const MIN_USABLE_MINUTES = 60;

    /** How long an unanswered check can still be answered (late, and recorded as missed). */
    private const ANSWERABLE_HOURS = 14;

    private const RETENTION_DAYS = 90;

    public function __construct(private readonly PlaceProof $proof, private readonly AttendancePlaces $places) {}

    /**
     * When this record's checks fall, as UTC instants keyed by slot (from 1).
     * Empty when the shift is too short, or there is no shift or check-in.
     *
     * @return array<int, Carbon>
     */
    public function times(AttendanceRecord $record, int $perShift, string $zone): array
    {
        $shift = $record->shift;
        if ($perShift < 1 || $shift === null || $record->check_in === null) {
            return [];
        }

        [$start, $end] = $shift->windowOn($record->workDate($zone), $zone);

        $from = max($record->check_in->getTimestamp(), $start->getTimestamp()) + self::MARGIN_MINUTES * 60;
        $to = $end->getTimestamp() - (self::MARGIN_MINUTES + AttendanceRules::PRESENCE_RESPONSE_MINUTES) * 60;
        if ($to <= $from) {
            return [];
        }

        // Lunch is not on the shift, so the middle of the day is kept clear:
        // half the shift's break each way, with some padding.
        $middle = intdiv($start->getTimestamp() + $end->getTimestamp(), 2);
        $half = (intdiv((int) $shift->break_minutes, 2) + self::MIDDLE_BAND_PADDING_MINUTES) * 60;
        $intervals = [];
        foreach ([[$from, min($to, $middle - $half)], [max($from, $middle + $half), $to]] as [$a, $b]) {
            if ($b > $a) {
                $intervals[] = [$a, $b];
            }
        }

        $usable = array_sum(array_map(static fn (array $i): int => $i[1] - $i[0], $intervals));
        if ($usable < self::MIN_USABLE_MINUTES * 60) {
            return [];
        }

        $count = 1 + ($this->seed($record, 0) % $perShift);
        $slice = intdiv($usable, $count);

        $times = [];
        for ($slot = 1; $slot <= $count; $slot++) {
            $offset = ($slot - 1) * $slice + ($this->seed($record, $slot) % $slice);
            $times[$slot] = Carbon::createFromTimestampUTC($this->place($intervals, $offset));
        }

        return $times;
    }

    /**
     * The slot to send now, or null.
     */
    public function due(AttendanceRecord $record, Employee $employee, AttendanceSetting $policy, string $zone, CarbonInterface $now): ?int
    {
        $perShift = (int) $policy->presence_checks_per_shift;
        if ($perShift < 1 || $record->check_out !== null || $record->status === AttendanceStatus::VOIDED || $record->shift === null || $record->check_in === null) {
            return null;
        }

        if ($this->places->for($employee) === []) {
            return null;
        }

        // Someone on a break is not asked: a lunch out of the building is
        // what a break is.
        $breaks = $record->metadata['breaks'] ?? null;
        if (is_array($breaks) && $breaks !== []) {
            $last = end($breaks);
            if (is_array($last) && ($last['type'] ?? null) === 'out') {
                return null;
            }
        }

        $rows = AttendancePresenceCheck::query()->where('attendance_record_id', $record->id)->get(['slot', 'status', 'expires_at']);
        if ($rows->contains(fn (AttendancePresenceCheck $row): bool => $row->status === AttendancePresenceCheck::SENT && $row->expires_at->gt($now))) {
            return null;
        }

        [, $end] = $record->shift->windowOn($record->workDate($zone), $zone);
        if ($now->gt($end->copy()->subMinutes(AttendanceRules::PRESENCE_RESPONSE_MINUTES))) {
            return null;
        }

        $taken = $rows->pluck('slot')->map(fn (mixed $slot): int => (int) $slot)->all();
        foreach ($this->times($record, $perShift, $zone) as $slot => $at) {
            if ($at->lte($now) && ! in_array($slot, $taken, true)) {
                return $slot;
            }
        }

        return null;
    }

    /**
     * Record the check and push it. The deadline runs from the real send, so
     * a tick that is late still gives the full window. A second tick that
     * got here first leaves a row behind: nothing is sent twice.
     */
    public function send(AttendanceRecord $record, Employee $employee, User $user, int $slot, CarbonInterface $now, WebPushSender $sender, string $locale): AttendancePresenceCheck
    {
        try {
            $check = AttendancePresenceCheck::query()->create([
                'tenant_id' => $record->tenant_id,
                'employee_id' => $employee->id,
                'attendance_record_id' => $record->id,
                'slot' => $slot,
                'sent_at' => $now,
                'expires_at' => $now->copy()->addMinutes(AttendanceRules::PRESENCE_RESPONSE_MINUTES),
                'status' => AttendancePresenceCheck::SENT,
            ]);
        } catch (UniqueConstraintViolationException) {
            return AttendancePresenceCheck::query()
                ->where('attendance_record_id', $record->id)
                ->where('slot', $slot)
                ->firstOrFail();
        }

        $sender->toUser($user->id, [
            'title' => 'ETHR',
            'body' => __('attendance.push_presence_check', ['minutes' => AttendanceRules::PRESENCE_RESPONSE_MINUTES], $locale),
            'url' => '/attendance/mobile',
            'tag' => 'presence-check',
            'ttl' => AttendanceRules::PRESENCE_RESPONSE_MINUTES * 60,
            'urgency' => 'high',
            'requireInteraction' => true,
        ]);

        return $check;
    }

    /** Checks nobody answered in time become `missed`. One statement. */
    public function expire(CarbonInterface $now): int
    {
        return AttendancePresenceCheck::query()
            ->where('status', AttendancePresenceCheck::SENT)
            ->where('expires_at', '<', $now)
            ->update(['status' => AttendancePresenceCheck::MISSED]);
    }

    /** Rows past their retention. */
    public function purge(CarbonInterface $now): int
    {
        return AttendancePresenceCheck::query()
            ->where('sent_at', '<', $now->copy()->subDays(self::RETENTION_DAYS))
            ->delete();
    }

    /** The check waiting for this person's answer, if any. */
    public function pendingFor(Employee $employee, CarbonInterface $now): ?AttendancePresenceCheck
    {
        return AttendancePresenceCheck::query()
            ->where('employee_id', $employee->id)
            ->where('status', AttendancePresenceCheck::SENT)
            ->where('expires_at', '>', $now)
            ->orderByDesc('sent_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Answer the person's own latest check. Null when there is none to answer.
     *
     * The time decides: an answer after `expires_at` is `missed` and keeps its
     * place and time. In time, a phone the organisation does not allow is
     * `unverified` (under `enforce` it is refused before this point), a
     * position outside every workplace is `outside` (and filed for review),
     * anything else is `confirmed`. A repeat of the same request, or a second
     * answer, returns what was stored first.
     *
     * @param  array<string, mixed>  $data  the validated request
     */
    public function answer(Employee $employee, Request $request, array $data, CarbonInterface $now): ?AttendancePresenceCheck
    {
        $replay = AttendancePresenceCheck::query()
            ->where('employee_id', $employee->id)
            ->where('idempotency_key', (string) $data['idempotency_key'])
            ->first();
        if ($replay !== null) {
            return $replay;
        }

        $check = AttendancePresenceCheck::query()
            ->where('employee_id', $employee->id)
            ->whereNull('answered_at')
            ->whereIn('status', [AttendancePresenceCheck::SENT, AttendancePresenceCheck::MISSED])
            ->where('sent_at', '>=', $now->copy()->subHours(self::ANSWERABLE_HOURS))
            ->orderByDesc('sent_at')
            ->orderByDesc('id')
            ->first();
        if ($check === null) {
            return null;
        }

        $lat = (float) $data['latitude'];
        $lng = (float) $data['longitude'];
        $accuracy = isset($data['accuracy']) && is_numeric($data['accuracy']) ? (float) $data['accuracy'] : null;

        $taken = $this->proof->take($employee, $request, $data['device_proof'] ?? null, live: true, lat: $lat, lng: $lng, accuracy: $accuracy);
        $verdict = $taken['verdict'];
        $place = $taken['check'];

        $late = $now->gt($check->expires_at);
        $status = match (true) {
            $late => AttendancePresenceCheck::MISSED,
            ! $verdict->allowed() => AttendancePresenceCheck::UNVERIFIED,
            $place['inside'] === false => AttendancePresenceCheck::OUTSIDE,
            default => AttendancePresenceCheck::CONFIRMED,
        };
        $distance = $place['distance_meters'] === null ? null : (int) round($place['distance_meters']);

        // Conditional on being unanswered, so two answers racing have one winner.
        $won = AttendancePresenceCheck::query()
            ->whereKey($check->id)
            ->whereNull('answered_at')
            ->update([
                'answered_at' => $now,
                'status' => $status,
                'latitude' => $lat,
                'longitude' => $lng,
                'accuracy' => $accuracy,
                'distance_meters' => $distance,
                'device_verdict' => $verdict->metadata()['device_verdict'] ?? null,
                'idempotency_key' => (string) $data['idempotency_key'],
            ]);

        $check = $check->fresh() ?? $check;

        if ($won === 1 && $status === AttendancePresenceCheck::OUTSIDE) {
            $this->fileOutside($check, $place, $distance);
        }

        return $check;
    }

    /**
     * An answer from outside the workplace, in time, is a finding at once.
     *
     * @param  array{inside: bool|null, nearest: array<string, mixed>|null, distance_meters: float|null}  $place
     */
    private function fileOutside(AttendancePresenceCheck $check, array $place, ?int $distance): void
    {
        $record = $check->record;
        if ($record === null) {
            return;
        }

        AttendanceIntegrityFlag::query()->firstOrCreate(
            ['attendance_record_id' => $record->id, 'punch' => 'presence', 'type' => 'outside_geofence'],
            [
                'tenant_id' => $record->tenant_id,
                'employee_id' => $record->employee_id,
                'severity' => 'high',
                'details' => [
                    'source' => 'presence',
                    'asked_at' => $check->sent_at->toIso8601String(),
                    'answered_at' => $check->answered_at?->toIso8601String(),
                    'distance_meters' => $distance,
                    'nearest' => $place['nearest'],
                ],
                'status' => 'open',
            ],
        );
    }

    private function seed(AttendanceRecord $record, int $slot): int
    {
        return (int) hexdec(substr(hash_hmac('sha256', "presence|{$record->id}|{$slot}", (string) config('app.key')), 0, 8));
    }

    /**
     * The instant $offset seconds into the usable time, walking the intervals.
     *
     * @param  list<array{0: int, 1: int}>  $intervals
     */
    private function place(array $intervals, int $offset): int
    {
        foreach ($intervals as [$a, $b]) {
            if ($offset < $b - $a) {
                return $a + $offset;
            }
            $offset -= $b - $a;
        }

        $last = end($intervals);

        return $last[1];
    }
}
