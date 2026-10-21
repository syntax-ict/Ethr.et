<?php

declare(strict_types=1);

namespace App\Services\Device;

use App\Exceptions\AttendanceRefused;
use App\Models\AuditLog;
use App\Models\Device;
use App\Models\Employee;
use App\Models\UnmatchedDevicePunch;
use App\Models\User;
use App\Services\Identity\IdentityResolver;
use App\Services\Identity\IdentitySignals;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
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
    public function __construct(private readonly IdentityResolver $resolver) {}

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
     * next punches resolve at once, and replay every held punch through the
     * normal device path.
     *
     * @return array{replayed: int, refused: int}
     */
    public function assign(Device $device, string $badge, Employee $employee, User $by, DevicePunchRecorder $recorder): array
    {
        $signals = new IdentitySignals(
            employeeCode: $badge,
            badgeNumber: $badge,
            sourceType: $device->adapter_type,
            sourceRef: 'device:'.$device->id,
            identifierType: 'device_user_id',
            identifierValue: $badge,
        );
        $this->resolver->link($employee, $signals, 1.0, verified: true);

        $replayed = 0;
        $refused = 0;

        $this->pending($device, $badge)->each(function (UnmatchedDevicePunch $punch) use ($device, $employee, $by, $recorder, &$replayed, &$refused) {
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
        });

        AuditLog::record('device.badge_assigned', $device, [
            'badge' => $badge,
            'employee_id' => $employee->public_id,
            'replayed' => $replayed,
            'refused' => $refused,
        ]);

        return ['replayed' => $replayed, 'refused' => $refused];
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

    /** @return Collection<int, UnmatchedDevicePunch> */
    private function pending(Device $device, string $badge): Collection
    {
        return UnmatchedDevicePunch::query()
            ->where('device_id', $device->id)
            ->where('badge', $badge)
            ->where('status', UnmatchedDevicePunch::PENDING)
            ->orderBy('id')
            ->get();
    }
}
