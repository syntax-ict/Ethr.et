<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\AttendanceSource;
use App\Events\DeviceOffline;
use App\Models\Device;
use App\Models\DeviceSyncLog;
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

    public int $tries = 2;

    public int $timeout = 120;

    public function __construct(
        private readonly Device $device,
        private readonly string $triggeredBy = 'schedule',
    ) {}

    public function handle(DeviceManager $manager, AttendanceEngine $engine): void
    {
        $startedAt = now();
        $syncLog = DeviceSyncLog::create([
            'tenant_id' => $this->device->tenant_id,
            'device_id' => $this->device->id,
            'status' => 'running',
            'triggered_by' => $this->triggeredBy,
            'started_at' => $startedAt,
        ]);

        try {
            $adapter = $manager->adapter($this->device);
            $status = $adapter->getStatus($this->device);

            if (! ($status['online'] ?? false)) {
                $wasOnline = $this->device->status !== 'offline';
                $this->device->update(['status' => 'offline']);

                if ($wasOnline) {
                    DeviceOffline::dispatch($this->device);
                }

                $syncLog->update([
                    'status' => 'offline',
                    'error_message' => 'Device is not reachable',
                    'completed_at' => now(),
                    'duration_ms' => $startedAt->diffInMilliseconds(now()),
                ]);

                return;
            }

            if ($this->device->status !== 'online') {
                $this->device->update(['status' => 'online']);
            }

            $since = $this->device->last_sync_at?->format('Y-m-d\TH:i:sP');
            $events = $adapter->pullEvents($this->device, $since);

            $processed = 0;
            $failed = 0;

            foreach ($events as $event) {
                $employee = Employee::withoutGlobalScope('tenant')
                    ->where('tenant_id', $this->device->tenant_id)
                    ->where(function ($q) use ($event) {
                        $q->where('badge_number', $event['employee_badge'])
                            ->orWhere('employee_code', $event['employee_badge']);
                    })
                    ->first();

                if (! $employee) {
                    $failed++;
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
                    $failed++;
                    Log::warning('Device event processing failed', [
                        'device_id' => $this->device->id,
                        'badge' => $event['employee_badge'],
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            $this->device->update(['last_sync_at' => now()]);

            $logStatus = $failed > 0 && $processed > 0 ? 'partial' : ($failed > 0 ? 'failed' : 'success');

            $syncLog->update([
                'status' => $logStatus,
                'events_found' => count($events),
                'events_processed' => $processed,
                'events_failed' => $failed,
                'completed_at' => now(),
                'duration_ms' => $startedAt->diffInMilliseconds(now()),
            ]);

            Log::info('Device event pull complete', [
                'device_id' => $this->device->id,
                'events_found' => count($events),
                'processed' => $processed,
                'failed' => $failed,
            ]);
        } catch (\Throwable $e) {
            $this->device->update(['status' => 'error']);

            $syncLog->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'completed_at' => now(),
                'duration_ms' => $startedAt->diffInMilliseconds(now()),
            ]);

            Log::error('Device sync failed', [
                'device_id' => $this->device->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
