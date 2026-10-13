<?php

declare(strict_types=1);

namespace App\Services\Device;

use App\Models\Device;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Which device an ADMS request comes from, and whether it may speak for it.
 *
 * ADMS firmware sends its serial number and nothing else — no token, no
 * header ETHR can choose — so this is the webhook policy for token-less
 * firmware (DeviceController::resolveWebhookDevice), applied strictly:
 *
 * - The serial only **selects** a device, and only one registered as
 *   `zkteco_adms`. Serials are printed on the case; on their own they prove
 *   nothing.
 * - The request must come from an address on the device's IP allowlist. An
 *   empty allowlist admits nobody (Device::webhookIpAllowed is fail-closed).
 * - Two devices with one serial authenticate neither.
 *
 * A refusal for a known serial is remembered for a week with the address it
 * came from, so the device page can say "tried to connect from 196.x.x.x" —
 * which is what an administrator needs to fill in the allowlist.
 *
 * Pre-authentication and cross-tenant by nature, like the webhook lookup: the
 * tenant is not known until the device is found. The query states no
 * `tenant_id`; `adapter_type` and the global unique index on
 * (serial_number, adapter_type) for live devices make the serial name one row.
 */
final class AdmsDeviceResolver
{
    public const ADAPTER = 'zkteco_adms';

    public function resolve(?string $serial, ?string $ip): ?Device
    {
        $serial = trim((string) $serial);
        if ($serial === '' || strlen($serial) > 64) {
            return null;
        }

        $candidates = Device::withoutGlobalScope('tenant')
            ->where('serial_number', $serial)
            ->where('adapter_type', self::ADAPTER)
            ->limit(2)
            ->get();

        if ($candidates->count() !== 1) {
            $this->log($serial, $ip, $candidates->isEmpty() ? 'unknown_serial' : 'serial_ambiguous');

            return null;
        }

        $device = $candidates->first();

        if (! $device->webhookIpAllowed($ip)) {
            $this->log($serial, $ip, 'ip_not_allowlisted');
            Cache::put(self::rejectionKey($device), ['ip' => $ip, 'at' => now()->toIso8601String()], now()->addWeek());

            return null;
        }

        return $device;
    }

    /**
     * The last refused attempt for this device, if any.
     *
     * @return array{ip: string|null, at: string}|null
     */
    public static function lastRejection(Device $device): ?array
    {
        try {
            $value = Cache::get(self::rejectionKey($device));
        } catch (\Throwable) {
            return null;
        }

        return is_array($value) && isset($value['at']) ? $value : null;
    }

    private static function rejectionKey(Device $device): string
    {
        return 'adms:rejected:'.$device->id;
    }

    private function log(string $serial, ?string $ip, string $reason): void
    {
        try {
            Log::warning('ADMS request rejected', ['serial' => $serial, 'ip' => $ip, 'reason' => $reason]);
        } catch (\Throwable) {
            // A downed log sink must not turn a refusal into a 500.
        }
    }
}
