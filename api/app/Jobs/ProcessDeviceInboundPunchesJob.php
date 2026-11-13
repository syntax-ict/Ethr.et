<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Exceptions\AttendanceRefused;
use App\Models\Device;
use App\Models\DeviceInboundPunch;
use App\Models\Tenant;
use App\Services\CurrentTenant;
use App\Services\Device\DevicePunchRecorder;
use App\Services\Device\UnmatchedPunches;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\LostConnectionException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PDOException;
use Throwable;

/**
 * Works one device's inbox (device_inbound_punches) in arrival order.
 *
 * A terminal in push mode posts one event at a time and re-sends anything it
 * is not answered 200 for, so the request only stores the punch and this job
 * does the engine's work. Order is the row id: the engine's `auto` direction
 * reads the open record, so a check-out processed before its check-in would
 * open a new day. One run at a time per device (unique until processing, and
 * a lock for the case of several workers).
 *
 * The device and its tenant are by id, the tenant a required argument stated
 * by the job and never read off the device it fetches.
 */
class ProcessDeviceInboundPunchesJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    /**
     * Unique until processing starts, so a job that is queued and never runs
     * (jobs row cleared, lost in a deploy drain) must not hold the lock for
     * good: it expires and the next dispatch gets through.
     */
    public int $uniqueFor = 300;

    /** Rows read per query. */
    private const BATCH = 50;

    /** Rows one run takes before handing the rest to a fresh job. */
    private const MAX_ROWS_PER_RUN = 200;

    /**
     * Wall-clock budget of one run. Production drains the queue for a few
     * seconds after a response, and a worker's --max-time is only checked
     * between jobs, so the job stops itself.
     */
    private const MAX_SECONDS_PER_RUN = 4;

    /** A row that has failed this often is marked processed with its error. */
    private const MAX_ATTEMPTS = 5;

    public function __construct(
        public readonly int $deviceId,
        public readonly int $tenantId,
    ) {
        $this->onQueue('attendance');
    }

    public function uniqueId(): string
    {
        return (string) $this->deviceId;
    }

    /**
     * Queue a run for every device whose punches have waited past two minutes:
     * a job that failed all its tries leaves its rows pending, and a
     * heartbeat inserts no row to dispatch another. A platform sweep, so it
     * reads across organisations; each job states its own tenant.
     *
     * @return int how many devices were queued
     */
    public static function sweepStranded(int $limit = 500): int
    {
        $stranded = DB::table('device_inbound_punches')
            ->whereNull('processed_at')
            ->where('created_at', '<', now()->subMinutes(2))
            ->distinct()
            ->limit($limit)
            ->get(['device_id', 'tenant_id']);

        foreach ($stranded as $row) {
            self::dispatch((int) $row->device_id, (int) $row->tenant_id);
        }

        return $stranded->count();
    }

    public function handle(DevicePunchRecorder $punches, UnmatchedPunches $unmatched): void
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

        $lock = Cache::lock("device-inbox:{$this->deviceId}", 120);
        if (! $lock->get()) {
            $this->release(5);

            return;
        }

        try {
            $this->drain($device, $punches, $unmatched);
        } finally {
            $lock->release();
        }
    }

    private function drain(Device $device, DevicePunchRecorder $punches, UnmatchedPunches $unmatched): void
    {
        $startedAt = microtime(true);
        $taken = 0;
        $latestMatched = null;

        try {
            while (true) {
                $rows = DeviceInboundPunch::query()
                    ->where('tenant_id', $this->tenantId)
                    ->where('device_id', $device->id)
                    ->whereNull('processed_at')
                    ->orderBy('id')
                    ->limit(self::BATCH)
                    ->get();

                if ($rows->isEmpty()) {
                    return;
                }

                foreach ($rows as $row) {
                    if ($taken >= self::MAX_ROWS_PER_RUN || (microtime(true) - $startedAt) >= self::MAX_SECONDS_PER_RUN) {
                        // Unique-until-processing released its lock when this
                        // run began, so a fresh job can be queued for the rest.
                        self::dispatch($this->deviceId, $this->tenantId);

                        return;
                    }

                    $taken++;

                    if ($this->process($row, $device, $punches, $unmatched)) {
                        $latestMatched = $latestMatched === null || $row->created_at->gt($latestMatched)
                            ? $row->created_at
                            : $latestMatched;
                    }
                }
            }
        } finally {
            // Moved by the receipt time, not the processing time: for a
            // terminal ETHR also pulls, `last_sync_at` is the pull cursor.
            if ($latestMatched !== null) {
                $current = $device->fresh()?->last_sync_at;
                $device->update([
                    'last_sync_at' => $current !== null && $current->gt($latestMatched) ? $current : $latestMatched,
                    'status' => 'online',
                ]);
            }
        }
    }

    /**
     * @return bool whether a punch was recorded for an employee
     */
    private function process(DeviceInboundPunch $row, Device $device, DevicePunchRecorder $punches, UnmatchedPunches $unmatched): bool
    {
        if ($row->attempts >= self::MAX_ATTEMPTS) {
            $this->finish($row, 'gave up after '.self::MAX_ATTEMPTS.' attempts');

            return false;
        }

        try {
            $employee = $punches->employee($device, $row->badge);

            if ($employee === null) {
                // Held, not dropped: replayed once someone says whose badge it is.
                $unmatched->hold($device, $row->badge, $row->punch_type, $row->idempotency_key, $row->occurred_raw);
                $this->finish($row, null);

                return false;
            }

            $punches->record($device, $employee, $row->punch_type, $row->idempotency_key, $row->occurred_raw);
            $this->finish($row, null);

            return true;
        } catch (AttendanceRefused $e) {
            $this->finish($row, $e->getMessage());

            return false;
        } catch (QueryException|PDOException|DeadlockException|LostConnectionException $e) {
            // The row stays pending and the rows after it wait: order is
            // kept, and the job is retried. A deadlock and a lost connection
            // are thrown by the transaction wrapper as their own types.
            $row->forceFill(['attempts' => $row->attempts + 1])->save();

            throw $e;
        } catch (Throwable $e) {
            // One bad punch must not hold the device's queue.
            report($e);
            $this->finish($row, $e->getMessage());

            return false;
        }
    }

    private function finish(DeviceInboundPunch $row, ?string $error): void
    {
        $row->forceFill([
            'processed_at' => Carbon::now(),
            'error' => $error === null ? null : mb_substr($error, 0, 255),
        ])->save();
    }
}
