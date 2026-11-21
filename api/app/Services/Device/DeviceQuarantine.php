<?php

declare(strict_types=1);

namespace App\Services\Device;

use App\Jobs\ProcessDeviceInboundPunchesJob;
use App\Models\Device;
use App\Models\DeviceInboundPunch;
use Illuminate\Database\Eloquent\Builder;

/**
 * The punches a terminal sent that ETHR could not record: kept with what the
 * terminal sent, until a person replays or dismisses them.
 *
 * A row is in quarantine while `failed_at` is set and `dismissed_at` is not.
 * Replay puts the row back in the inbox as if it had just arrived, so the
 * queued job works it again in the device's arrival order; a replay of a punch
 * already recorded is a duplicate, never a second record. Dismiss keeps the
 * row (and its raw event) for a week, then retention removes it.
 *
 * Every query states the organisation: the model's scope is fail-closed, and
 * these are the actions a person takes on someone's attendance.
 */
final class DeviceQuarantine
{
    /**
     * The reasons a replay cannot help: the terminal sent no time, or a line
     * ETHR cannot read, so the same row fails again (and, for a line, would
     * be handled as a badge nobody holds and its raw text cleared).
     */
    private const NOT_REPLAYABLE = ['no_time', 'unreadable'];

    /** The most row ids an audit entry carries; the count says how many there were. */
    public const AUDIT_IDS = 200;

    /** @return Builder<DeviceInboundPunch> */
    public function inQuarantine(int $tenantId): Builder
    {
        return DeviceInboundPunch::query()
            ->where('tenant_id', $tenantId)
            ->whereNotNull('failed_at')
            ->whereNull('dismissed_at');
    }

    /**
     * @param  list<string>  $publicIds
     * @return array{replayed: int, skipped: int, ids: list<string>, devices: list<string>}
     */
    public function replay(int $tenantId, array $publicIds): array
    {
        $rows = $this->inQuarantine($tenantId)->whereIn('public_id', $publicIds)->get(['id', 'public_id', 'device_id', 'failure']);

        $replayable = $rows->reject(fn (DeviceInboundPunch $row) => in_array($row->failure, self::NOT_REPLAYABLE, true));

        if ($replayable->isNotEmpty()) {
            DeviceInboundPunch::query()
                ->where('tenant_id', $tenantId)
                ->whereIn('id', $replayable->pluck('id')->all())
                ->update([
                    'processed_at' => null,
                    'failed_at' => null,
                    'failure' => null,
                    'error' => null,
                    'attempts' => 0,
                ]);

            foreach ($replayable->pluck('device_id')->unique() as $deviceId) {
                ProcessDeviceInboundPunchesJob::dispatch((int) $deviceId, $tenantId);
            }
        }

        return [
            'replayed' => $replayable->count(),
            'skipped' => $rows->count() - $replayable->count(),
            'ids' => $replayable->pluck('public_id')->take(self::AUDIT_IDS)->values()->all(),
            'devices' => $this->devicePublicIds($tenantId, $replayable->pluck('device_id')->unique()->all()),
        ];
    }

    /**
     * @param  list<string>  $publicIds
     * @return array{dismissed: int, ids: list<string>, devices: list<string>}
     */
    public function dismiss(int $tenantId, array $publicIds): array
    {
        $rows = $this->inQuarantine($tenantId)->whereIn('public_id', $publicIds)->get(['id', 'public_id', 'device_id']);

        $dismissed = $rows->isEmpty() ? 0 : DeviceInboundPunch::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('id', $rows->pluck('id')->all())
            ->update(['dismissed_at' => now(), 'updated_at' => now()]);

        return [
            'dismissed' => $dismissed,
            'ids' => $rows->pluck('public_id')->take(self::AUDIT_IDS)->values()->all(),
            'devices' => $this->devicePublicIds($tenantId, $rows->pluck('device_id')->unique()->all()),
        ];
    }

    /**
     * @param  list<int>  $deviceIds
     * @return list<string>
     */
    private function devicePublicIds(int $tenantId, array $deviceIds): array
    {
        if ($deviceIds === []) {
            return [];
        }

        return Device::query()->where('tenant_id', $tenantId)->whereIn('id', $deviceIds)->pluck('public_id')->map(fn ($id) => (string) $id)->values()->all();
    }
}
