<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\PullDeviceEventsJob;
use App\Models\Device;
use App\Models\Tenant;
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
            $devices = Device::withoutGlobalScope('tenant')
                ->where('tenant_id', $tenant->id)
                ->where('auto_sync', true)
                ->whereIn('status', ['online', 'pending'])
                ->get();

            foreach ($devices as $device) {
                if (! $device->isDueForSync()) {
                    continue;
                }

                PullDeviceEventsJob::dispatch($device);
                $totalDispatched++;
            }
        });

        $this->info("Dispatched sync jobs for {$totalDispatched} device(s).");

        return self::SUCCESS;
    }
}
