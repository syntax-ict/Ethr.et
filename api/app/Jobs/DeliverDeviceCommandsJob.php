<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Device;
use App\Models\Tenant;
use App\Services\CurrentTenant;
use App\Services\Device\DeviceCommandQueue;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

/**
 * Deliver a device's pending commands (DeviceCommandQueue::deliver). Queued
 * when a command is added; PullDeviceEventsJob also delivers on every sync, so
 * a command for a device that was off goes out when it is back.
 */
class DeliverDeviceCommandsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(private readonly Device $device) {}

    /** @return list<object> */
    public function middleware(): array
    {
        // Ten edits in a row queue ten jobs; one delivery covers them all.
        return [(new WithoutOverlapping('device-commands-'.$this->device->id))->dontRelease()->expireAfter(120)];
    }

    public function handle(DeviceCommandQueue $queue): void
    {
        // Same reason as PullDeviceEventsJob: a worker resolves no tenant, and
        // the device is tenant-owned, so its tenant is the one to act as.
        $tenant = Tenant::query()->find($this->device->tenant_id);
        if ($tenant === null) {
            return; // the organisation was deleted after this was queued
        }
        app(CurrentTenant::class)->set($tenant);

        $queue->deliver($this->device);
    }
}
