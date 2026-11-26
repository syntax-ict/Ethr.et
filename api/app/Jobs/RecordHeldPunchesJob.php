<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Device;
use App\Models\Tenant;
use App\Services\CurrentTenant;
use App\Services\Device\DevicePunchRecorder;
use App\Services\Device\UnmatchedPunches;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Records the held punches of one badge whose number now names one current
 * employee, with no one's click (UnmatchedPunches::recordMatched). One slice a
 * run; while punches remain it queues itself again. Whether the badge may be
 * recorded is decided in `recordMatched()`, not here: a verdict that is not
 * actionable leaves everything held.
 *
 * The tenant is a required argument stated by the job, and the device is
 * looked up inside it.
 */
class RecordHeldPunchesJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    /** A job queued and never run must not hold its lock for good. */
    public int $uniqueFor = 300;

    public function __construct(
        public readonly int $deviceId,
        public readonly int $tenantId,
        public readonly string $badge,
    ) {
        $this->onQueue('attendance');
    }

    public function uniqueId(): string
    {
        return $this->tenantId.':'.$this->deviceId.':'.md5(mb_strtolower(trim($this->badge)));
    }

    /**
     * Queue a run for each held badge that may be recorded by itself. A
     * platform sweep, so it reads across organisations; each job states its
     * own tenant.
     *
     * @return int how many badges were queued
     */
    public static function sweep(int $limit = 200): int
    {
        $candidates = UnmatchedPunches::sweepCandidates($limit);

        foreach ($candidates as $row) {
            self::dispatch($row['device_id'], $row['tenant_id'], $row['badge']);
        }

        return count($candidates);
    }

    public function handle(DevicePunchRecorder $recorder, UnmatchedPunches $unmatched): void
    {
        $tenant = Tenant::query()->find($this->tenantId);
        if ($tenant === null) {
            return; // the organisation was deleted after this was queued
        }
        app(CurrentTenant::class)->set($tenant);

        $device = Device::query()->where('tenant_id', $this->tenantId)->find($this->deviceId);
        if ($device === null) {
            return;
        }

        $result = $unmatched->recordMatched($device, $this->badge, $recorder);

        // Unique until processing released its lock when this run began, so
        // the next slice can be queued.
        if ($result !== null && $result['remaining'] > 0 && $result['replayed'] + $result['refused'] > 0) {
            self::dispatch($this->deviceId, $this->tenantId, $this->badge);
        }
    }
}
