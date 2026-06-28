<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\AttendanceSource;
use App\Events\DeviceOffline;
use App\Models\Device;
use App\Models\Employee;
use App\Services\Attendance\AttendanceEngine;
use App\Services\Attendance\AttendanceInput;
use App\Services\Device\DeviceManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class PullDeviceEventsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        private readonly Device $device,
    ) {}

    public function handle(DeviceManager $manager, AttendanceEngine $engine): void
    {
        $adapter = $manager->adapter($this->device);

        $status = $adapter->getStatus($this->device);

        if (! ($status['online'] ?? false)) {
            if ($this->device->status !== 'offline') {
                $this->device->update(['status' => 'offline']);
                DeviceOffline::dispatch($this->device);
            }

            return;
        }

        if ($this->device->status !== 'online') {
            $this->device->update(['status' => 'online']);
        }

        $since = $this->device->last_sync_at?->format('Y-m-d\TH:i:sP');
        $events = $adapter->pullEvents($this->device, $since);

        $processed = 0;

        foreach ($events as $event) {
            $employee = Employee::where('tenant_id', $this->device->tenant_id)
                ->where(function ($q) use ($event) {
                    $q->where('badge_number', $event['employee_badge'])
                        ->orWhere('employee_code', $event['employee_badge']);
                })
                ->first();

            if (! $employee) {
                Log::info('Device event: no matching employee', [
                    'device_id' => $this->device->id,
                    'badge' => $event['employee_badge'],
                ]);
                continue;
            }

            $idempotencyKey = "device:{$this->device->id}:{$event['employee_badge']}:{$event['timestamp']}";

            try {
                $engine->record(new AttendanceInput(
                    employeeId: $employee->id,
                    tenantId: $this->device->tenant_id,
                    source: AttendanceSource::BIOMETRIC,
                    type: $event['type'],
                    idempotencyKey: $idempotencyKey,
                    deviceId: $this->device->id,
                ));
                $processed++;
            } catch (\Throwable $e) {
                Log::warning('Device event processing failed', [
                    'device_id' => $this->device->id,
                    'badge' => $event['employee_badge'],
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->device->update(['last_sync_at' => now()]);

        Log::info('Device event pull complete', [
            'device_id' => $this->device->id,
            'events_found' => count($events),
            'processed' => $processed,
        ]);
    }
}
