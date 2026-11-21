<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\UserRole;
use App\Http\Middleware\AuthenticateApiKey;
use App\Models\Device;
use App\Models\Tenant;
use App\Services\Device\AdmsDeviceResolver;
use App\Services\Device\DeviceCommandQueue;
use App\Services\Device\DeviceDayCounts;
use App\Services\Device\DeviceManager;
use App\Services\Device\TerminalKeepAlive;
use App\Services\Device\WebhookRejections;
use App\Support\DeviceClock;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Device */
class DeviceResource extends JsonResource
{
    /** @var array{source: string|null, measurable: bool|null}|null */
    private ?array $clock = null;

    /** The request attribute a list fills with its devices' counts, so the page costs one query. */
    public const COUNTS_ATTRIBUTE = 'device_day_counts';

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
            'receives_only' => DeviceManager::hasNoRoster($this->resource),
            'pulled' => ! DeviceManager::receivesOnly($this->resource),
            'sync_interval_minutes' => $this->sync_interval_minutes,
            'timezone' => $this->timezone,
            'webhook_ip_allowlist' => $this->when(
                $request->user()?->isAtLeast(UserRole::TENANT_ADMIN),
                fn () => $this->webhook_ip_allowlist ?? [],
            ),
            'has_webhook_token' => $this->when(
                $this->showsToken($request),
                fn (): bool => $this->hasWebhookToken(),
            ),
            'webhook_token_prefix' => $this->when(
                $this->showsToken($request),
                fn (): ?string => $this->webhook_token_prefix,
            ),
            'webhook_url_masked' => $this->when(
                $this->showsToken($request) && $this->hasWebhookToken(),
                fn (): ?string => $this->webhookUrlFor((string) $this->webhook_token_prefix.'...'),
            ),
            'webhook_token' => $this->when(
                $this->showsToken($request) && $this->issuedWebhookToken !== null,
                fn (): ?string => $this->issuedWebhookToken,
            ),
            'webhook_url' => $this->when(
                $this->showsToken($request) && $this->issuedWebhookToken !== null,
                fn (): ?string => $this->webhookUrlFor((string) $this->issuedWebhookToken),
            ),
            'last_sync_at' => $this->last_sync_at?->toIso8601String(),
            'clock_offset_seconds' => $this->clock_offset_seconds,
            'clock_checked_at' => $this->clock_checked_at?->toIso8601String(),
            'clock_source' => $this->clockSource(),
            'clock_measurable' => $this->clockMeasurable(),
            'today' => $this->todayCounts($request),
            'adms' => $this->when(
                $this->adapter_type === AdmsDeviceResolver::ADAPTER && (bool) $request->user()?->isAtLeast(UserRole::TENANT_ADMIN),
                fn () => [
                    'server' => AdmsDeviceResolver::serverAddress(Tenant::query()->find($this->tenant_id)),
                    'last_rejected' => AdmsDeviceResolver::lastRejection($this->resource),
                ],
            ),
            'webhook_rejected' => $this->when(
                $this->showsToken($request) && in_array($this->adapter_type, ['hikvision', 'zkteco', 'suprema'], true),
                fn () => WebhookRejections::last($this->resource),
            ),
            'last_keepalive_at' => $this->when(
                $this->showsToken($request) && in_array($this->adapter_type, ['hikvision', 'zkteco', 'suprema'], true) && DeviceManager::receivesOnly($this->resource),
                fn () => TerminalKeepAlive::last($this->resource),
            ),
            'branch' => new BranchResource($this->whenLoaded('branch')),
            'branch_public_id' => $this->branch?->public_id,
            'attendance_records_count' => $this->whenCounted('attendanceRecords'),
            'sync_logs_count' => $this->whenCounted('syncLogs'),
            'quarantined_count' => $this->whenCounted('quarantinedPunches'),
            'latest_sync_log' => new DeviceSyncLogResource($this->whenLoaded('latestSyncLog')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }

    /** @return 'heartbeat'|'punch'|'heartbeat_no_time'|null */
    private function clockSource(): ?string
    {
        return $this->clock()['source'];
    }

    private function clockMeasurable(): ?bool
    {
        return $this->clock()['measurable'];
    }

    /** @return array{source: string|null, measurable: bool|null} */
    private function clock(): array
    {
        return $this->clock ??= DeviceClock::describe($this->resource);
    }

    /**
     * What this terminal sent today (DeviceDayCounts). A list has counted its
     * whole page in one query; a single device counts itself.
     *
     * @return array{day: string, received: int, recorded: int, held: int, duplicate: int, quarantined: int, waiting: int}|null
     */
    private function todayCounts(Request $request): ?array
    {
        $counted = $request->attributes->get(self::COUNTS_ATTRIBUTE);
        if (is_array($counted) && isset($counted[$this->id])) {
            return $counted[$this->id];
        }

        return DeviceDayCounts::forDevices((int) $this->tenant_id, [$this->resource])[$this->id] ?? null;
    }

    /**
     * The device's token is shown to an admin signed in, never through an
     * integration key, which creates or reads no credential (A-Z review G-01).
     */
    private function showsToken(Request $request): bool
    {
        return $request->user()?->isAtLeast(UserRole::TENANT_ADMIN) === true
            && ! $request->attributes->has(AuthenticateApiKey::ATTRIBUTE);
    }
}
