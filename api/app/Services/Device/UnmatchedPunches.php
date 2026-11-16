<?php

declare(strict_types=1);

namespace App\Services\Device;

use App\Exceptions\AttendanceRefused;
use App\Models\AuditLog;
use App\Models\Device;
use App\Models\Employee;
use App\Models\EmployeeExternalIdentity;
use App\Models\UnmatchedDevicePunch;
use App\Models\User;
use App\Services\Identity\IdentityResolver;
use App\Services\Identity\IdentitySignals;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Punches from a badge no employee matched: held, then replayed once someone
 * says whose badge it is, or dismissed (blueprint assessment AT3, AT20).
 *
 * Every device path used to log the badge and drop the punch. Held punches
 * keep the idempotency key they would have been recorded under, so a replay,
 * a re-pull or a redelivered webhook can never record one twice.
 */
final class UnmatchedPunches
{
    /** How long one request replays before it answers, the same twelve seconds as an import slice. */
    public const SLICE_SECONDS = 12.0;

    /** Longest the lock outlives a request that died; far over a slice and one punch. */
    private const LOCK_SECONDS = 60;

    /** Punches read at a time, so a badge with thousands is never loaded whole. */
    private const CHUNK = 50;

    public function __construct(
        private readonly IdentityResolver $resolver,
        private readonly float $sliceSeconds = self::SLICE_SECONDS,
    ) {}

    /**
     * Keep a punch whose badge matched no employee. A second delivery of the
     * same punch is already held and is ignored.
     */
    public function hold(Device $device, string $badge, string $type, string $idempotencyKey, string $occurredRaw): void
    {
        try {
            UnmatchedDevicePunch::create([
                'tenant_id' => $device->tenant_id,
                'device_id' => $device->id,
                'badge' => mb_substr($badge, 0, 64),
                'punch_type' => mb_substr($type, 0, 20),
                'occurred_raw' => mb_substr($occurredRaw, 0, 64),
                'idempotency_key' => mb_substr($idempotencyKey, 0, 191),
                'status' => UnmatchedDevicePunch::PENDING,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Already held.
        }
    }

    /**
     * Say whose badge it is: remember the mapping, verified, so the device's
     * next punches resolve at once, and replay the held punches through the
     * normal device path, earliest first.
     *
     * The host allows a request about thirty seconds, and a badge can hold
     * hundreds of punches, so the replay works for `sliceSeconds` and says how
     * many are left. It always takes at least one when any is pending, so every
     * call makes progress; asking again with the same three inputs carries on
     * where it stopped, and a punch already replayed or refused is not looked
     * at again. One lock per organisation, device and badge keeps two requests
     * from replaying the same punch; a caller that cannot take it is told to
     * try again (409), and nothing has been linked or replayed for it.
     *
     * Each call that replays or refuses a punch writes one audit entry with
     * its own counts: `device.badge_assigned` when it made or changed the
     * link, `device.held_punches_replayed` otherwise. A call that finds
     * nothing pending writes none.
     *
     * @return array{replayed: int, refused: int, remaining: int}
     */
    public function assign(Device $device, string $badge, Employee $employee, User $by, DevicePunchRecorder $recorder): array
    {
        $lock = Cache::lock(self::lockKey($device, $badge), self::LOCK_SECONDS);

        if (! $lock->get()) {
            abort(409, __('device.held_busy'));
        }

        try {
            $signals = IdentitySignals::forDeviceUser($device, $badge);
            $alreadyTheirs = $this->confirmedFor($device, $signals, $employee);

            // A person is saying whose number this is, so it moves even from
            // someone it was confirmed for; the resolver records who held it.
            $this->resolver->link($employee, $signals, 1.0, verified: true, reassign: true);

            [$replayed, $refused] = $this->replay($device, $badge, $employee, $by, $recorder);

            // Punches enter attendance at a person's click, so every call that
            // replays or refuses one leaves an entry with its own counts. It is
            // `device.badge_assigned` when this call made or changed the link,
            // and `device.held_punches_replayed` when it only carried on, or
            // the number was already confirmed (an import does that).
            if ($replayed + $refused > 0) {
                AuditLog::record($alreadyTheirs ? 'device.held_punches_replayed' : 'device.badge_assigned', $device, [
                    'badge' => $badge,
                    'employee_id' => $employee->public_id,
                    'replayed' => $replayed,
                    'refused' => $refused,
                ]);
            }

            return ['replayed' => $replayed, 'refused' => $refused, 'remaining' => $this->pending($device, $badge)->count()];
        } finally {
            $lock->release();
        }
    }

    /** What keeps two requests off the same badge of the same device. */
    public static function lockKey(Device $device, string $badge): string
    {
        return 'held-punches:'.$device->tenant_id.':'.$device->id.':'.md5(mb_strtolower(trim($badge)));
    }

    /**
     * Whether this person already holds this device's number, confirmed, as
     * the call starts: then there is nothing to say about the link again.
     */
    private function confirmedFor(Device $device, IdentitySignals $signals, Employee $employee): bool
    {
        return EmployeeExternalIdentity::query()
            ->where('tenant_id', $device->tenant_id)
            ->where('source_type', $signals->sourceType)
            ->where('source_ref', $signals->sourceRef ?? '')
            ->where('identifier_type', $signals->identifierType)
            ->where('identifier_value', $signals->identifierValue)
            ->where('employee_id', $employee->id)
            ->whereNotNull('verified_at')
            ->exists();
    }

    /**
     * Replay pending punches, earliest first, until the time is spent. The
     * first is always taken. Each is marked as it is handled, so a request
     * that dies leaves the rest pending.
     *
     * @return array{0: int, 1: int} replayed and refused
     */
    private function replay(Device $device, string $badge, Employee $employee, User $by, DevicePunchRecorder $recorder): array
    {
        $deadline = microtime(true) + $this->sliceSeconds;
        $replayed = 0;
        $refused = 0;

        while (true) {
            $punches = $this->pending($device, $badge)->limit(self::CHUNK)->get();

            if ($punches->isEmpty()) {
                break;
            }

            foreach ($punches as $punch) {
                if ($replayed + $refused > 0 && microtime(true) >= $deadline) {
                    break 2;
                }

                $status = UnmatchedDevicePunch::REPLAYED;
                $refusal = null;

                try {
                    $recorder->record($device, $employee, $punch->punch_type, $punch->idempotency_key, $punch->occurred_raw);
                    $replayed++;
                } catch (AttendanceRefused $e) {
                    $status = UnmatchedDevicePunch::REFUSED;
                    $refusal = mb_substr($e->getMessage(), 0, 255);
                    $refused++;
                }

                $punch->update([
                    'status' => $status,
                    'refusal' => $refusal,
                    'employee_id' => $employee->id,
                    'resolved_by' => $by->id,
                    'resolved_at' => now(),
                ]);
            }
        }

        return [$replayed, $refused];
    }

    /** Drop the held punches of a badge that is no one's. */
    public function dismiss(Device $device, string $badge, User $by): int
    {
        $count = $this->pending($device, $badge)->count();

        UnmatchedDevicePunch::query()
            ->where('device_id', $device->id)
            ->where('badge', $badge)
            ->where('status', UnmatchedDevicePunch::PENDING)
            ->update(['status' => UnmatchedDevicePunch::DISMISSED, 'resolved_by' => $by->id, 'resolved_at' => now()]);

        AuditLog::record('device.badge_dismissed', $device, ['badge' => $badge, 'punches' => $count]);

        return $count;
    }

    /**
     * Pending punches grouped by device and badge, most recent first.
     *
     * @return list<array{device_id: int, badge: string, punches: int, first_seen: string, last_seen: string}>
     */
    public function groups(): array
    {
        return UnmatchedDevicePunch::query()
            ->where('status', UnmatchedDevicePunch::PENDING)
            ->select([
                'device_id',
                'badge',
                DB::raw('COUNT(*) as punches'),
                DB::raw('MIN(created_at) as first_seen'),
                DB::raw('MAX(created_at) as last_seen'),
            ])
            ->groupBy('device_id', 'badge')
            ->orderByDesc('last_seen')
            ->limit(200)
            ->get()
            ->map(fn ($row) => [
                'device_id' => (int) $row->getAttribute('device_id'),
                'badge' => (string) $row->getAttribute('badge'),
                'punches' => (int) $row->getAttribute('punches'),
                'first_seen' => (string) $row->getAttribute('first_seen'),
                'last_seen' => (string) $row->getAttribute('last_seen'),
            ])
            ->values()
            ->all();
    }

    /**
     * @return Builder<UnmatchedDevicePunch>
     */
    private function pending(Device $device, string $badge): Builder
    {
        return UnmatchedDevicePunch::query()
            ->where('tenant_id', $device->tenant_id)
            ->where('device_id', $device->id)
            ->where('badge', $badge)
            ->where('status', UnmatchedDevicePunch::PENDING)
            ->orderBy('id');
    }
}
