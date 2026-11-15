<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\PullDeviceEventsJob;
use App\Models\Device;
use App\Models\Tenant;
use App\Services\Device\AdmsDeviceResolver;
use App\Services\Device\DeviceManager;
use App\Services\Device\HikvisionPushAdapter;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class SyncDevicesCommand extends Command
{
    /** Least wait between pulls of a device that failed the last time. */
    private const RETRY_AFTER_MINUTES = 15;

    protected $signature = 'devices:sync {--tenant= : Sync devices for a specific tenant public_id}';

    protected $description = 'Pull attendance events from all auto-sync enabled devices';

    public function handle(): int
    {
        $tenantId = $this->option('tenant');

        $query = Tenant::query();
        if ($tenantId) {
            $query->where('public_id', $tenantId);
        }

        $totalDispatched = 0;

        $query->each(function (Tenant $tenant) use (&$totalDispatched) {
            // Not only the devices due, since a terminal that only sends is
            // never pulled and this run is the only thing that can move it
            // off Online when it goes quiet. Only a Hikvision terminal can
            // be one (DeviceManager::receivesOnly reads the encrypted config,
            // so it cannot be filtered in SQL). A device that is neither
            // Hikvision nor auto-synced has nothing for this run, so it stays
            // in the database rather than being decrypted every five minutes.
            $devices = Device::withoutGlobalScope('tenant')
                ->where('tenant_id', $tenant->id)
                ->where(fn ($q) => $q->where('auto_sync', true)->orWhere('adapter_type', 'hikvision'))
                ->get();

            foreach ($devices as $device) {
                // One device whose config will not decrypt, or whose write
                // fails, is reported and skipped: it must not stop the run
                // for every device and organisation after it.
                try {
                    if ($this->handleDevice($device)) {
                        $totalDispatched++;
                    }
                } catch (\Throwable $e) {
                    report($e);
                }
            }
        });

        $this->info("Dispatched sync jobs for {$totalDispatched} device(s).");

        return self::SUCCESS;
    }

    /** Whether a pull was dispatched for the device. */
    private function handleDevice(Device $device): bool
    {
        // A terminal that only sends has nothing to pull. Its status follows
        // from when it was last heard, whatever it was.
        if (DeviceManager::receivesOnly($device)) {
            HikvisionPushAdapter::settleStatus($device);

            return false;
        }

        if (! $device->auto_sync) {
            return false;
        }

        $due = in_array($device->status, ['online', 'pending'], true)
            ? $device->isDueForSync()
            : $this->isDueForRetry($device);

        if (! $due) {
            return false;
        }

        PullDeviceEventsJob::dispatch($device->id, $device->tenant_id);

        return true;
    }

    /**
     * Whether a device that failed its last pull (offline or error) is tried
     * again. A device that failed once used to be parked until an admin
     * pressed Test or Pull, though the job itself brings it back online when
     * its probe succeeds. It is retried no more often than every 15 minutes,
     * or its own interval if that is longer, so dead devices do not eat the
     * queue's time budget: production drains the queue inside HTTP requests
     * of at most about 25 seconds. An ADMS terminal is never called, it comes
     * back by its own next call, so a retry would only write log rows.
     *
     * The last attempt is read with the tenant stated here, since this command
     * resolves no tenant and the scoped relation would return nothing.
     */
    private function isDueForRetry(Device $device): bool
    {
        if ($device->adapter_type === AdmsDeviceResolver::ADAPTER) {
            return false;
        }

        $last = DB::table('device_sync_logs')
            ->where('tenant_id', $device->tenant_id)
            ->where('device_id', $device->id)
            ->max('started_at');

        if ($last === null) {
            return true;
        }

        $wait = max(self::RETRY_AFTER_MINUTES, (int) $device->sync_interval_minutes);

        return Carbon::parse((string) $last, 'UTC')->addMinutes($wait)->lessThanOrEqualTo(now());
    }
}
