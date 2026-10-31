<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\UserRole;
use App\Models\Device;
use App\Models\Tenant;
use App\Services\Device\AdmsDeviceResolver;
use App\Services\Device\DeviceCommandQueue;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Device */
class DeviceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'name' => $this->name,
            'location_description' => $this->location_description,
            'serial_number' => $this->serial_number,
            'adapter_type' => $this->adapter_type,
            'status' => $this->status,
            'auto_sync' => $this->auto_sync,
            'commands_enabled' => (bool) $this->commands_enabled,
            'accepts_commands' => (bool) app(DeviceCommandQueue::class)->supports($this->resource),
            'sync_interval_minutes' => $this->sync_interval_minutes,
            'timezone' => $this->timezone,
            'webhook_ip_allowlist' => $this->when(
                $request->user()?->isAtLeast(UserRole::TENANT_ADMIN),
                fn () => $this->webhook_ip_allowlist ?? [],
            ),
            'webhook_token' => $this->when(
                $request->user()?->isAtLeast(UserRole::TENANT_ADMIN),
                $this->webhook_token,
            ),
            'webhook_url' => $this->when(
                $request->user()?->isAtLeast(UserRole::TENANT_ADMIN) && $this->webhook_token,
                fn () => $this->buildWebhookUrl(),
            ),
            'last_sync_at' => $this->last_sync_at?->toIso8601String(),
            'clock_offset_seconds' => $this->clock_offset_seconds,
            'clock_checked_at' => $this->clock_checked_at?->toIso8601String(),
            'adms' => $this->when(
                $this->adapter_type === AdmsDeviceResolver::ADAPTER && (bool) $request->user()?->isAtLeast(UserRole::TENANT_ADMIN),
                fn () => [
                    'server' => AdmsDeviceResolver::serverAddress(Tenant::query()->find($this->tenant_id)),
                    'last_rejected' => AdmsDeviceResolver::lastRejection($this->resource),
                ],
            ),
            'branch' => new BranchResource($this->whenLoaded('branch')),
            'branch_public_id' => $this->branch?->public_id,
            'attendance_records_count' => $this->whenCounted('attendanceRecords'),
            'sync_logs_count' => $this->whenCounted('syncLogs'),
            'latest_sync_log' => new DeviceSyncLogResource($this->whenLoaded('latestSyncLog')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }

    private function buildWebhookUrl(): ?string
    {
        $type = $this->adapter_type;

        if (! in_array($type, ['hikvision', 'zkteco', 'suprema'])) {
            return null;
        }

        $base = config('app.url').'/api/v1/devices/webhook/'.$type;

        return $base.'?token='.$this->webhook_token;
    }
}
