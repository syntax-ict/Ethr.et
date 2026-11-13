<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\PullDeviceEventsJob;
use App\Models\Device;
use App\Models\Tenant;
use App\Services\Device\DeviceManager;
use App\Services\Device\HikvisionPushAdapter;
use Illuminate\Console\Command;

class SyncDevicesCommand extends Command
{
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

        if (! $device->auto_sync
            || ! in_array($device->status, ['online', 'pending'], true)
            || ! $device->isDueForSync()) {
            return false;
        }

        PullDeviceEventsJob::dispatch($device->id, $device->tenant_id);

        return true;
    }
}
