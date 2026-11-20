<?php

declare(strict_types=1);

namespace App\Services\Device;

use App\Jobs\ProcessDeviceInboundPunchesJob;
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
    /** The reasons a replay cannot help: the terminal sent no time, so the same row fails again. */
    private const NOT_REPLAYABLE = ['no_time'];

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
     * @return array{replayed: int, skipped: int}
     */
    public function replay(int $tenantId, array $publicIds): array
    {
        $rows = $this->inQuarantine($tenantId)->whereIn('public_id', $publicIds)->get(['id', 'device_id', 'failure']);

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

        return ['replayed' => $replayable->count(), 'skipped' => $rows->count() - $replayable->count()];
    }

    /**
     * @param  list<string>  $publicIds
     * @return int how many were dismissed
     */
    public function dismiss(int $tenantId, array $publicIds): int
    {
        return $this->inQuarantine($tenantId)
            ->whereIn('public_id', $publicIds)
            ->update(['dismissed_at' => now(), 'updated_at' => now()]);
    }
}
