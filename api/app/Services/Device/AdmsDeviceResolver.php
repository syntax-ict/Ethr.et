<?php

declare(strict_types=1);

namespace App\Services\Device;

use App\Models\Device;
use App\Models\Tenant;
use App\Support\InstallMode;
use App\Support\TenancyDomain;
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
 * - The request must arrive on the organisation's own address (its
 *   subdomain, or its verified custom domain), which is the server address
 *   the device page tells the administrator to enter on the terminal. A
 *   serial is printed on the case, so registering one proves nothing; the
 *   address the terminal was set up to call does, because only someone at
 *   the terminal can set it (owner decision 2026-10-31). Where the deployment
 *   gives organisations no address of their own, this step does not apply.
 *
 * A refusal for a known serial is remembered for a week with the address it
 * came from, so the device page can say "tried to connect from 196.x.x.x" —
 * which is what an administrator needs to fill in the allowlist. Only a
 * request that reached the organisation's own address is remembered: one
 * aimed elsewhere came from a terminal someone else set up.
 *
 * Pre-authentication and cross-tenant by nature, like the webhook lookup: the
 * tenant is not known until the device is found. The query states no
 * `tenant_id`; `adapter_type` and the global unique index on
 * (serial_number, adapter_type) for live devices make the serial name one row.
 */
final class AdmsDeviceResolver
{
    public const ADAPTER = 'zkteco_adms';

    public function resolve(?string $serial, ?string $ip, ?string $host = null): ?Device
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

        if (! self::arrivedOnOwnAddress($device, $host)) {
            $this->log($serial, $ip, 'not_own_address');

            return null;
        }

        if (! $device->webhookIpAllowed($ip)) {
            $this->log($serial, $ip, 'ip_not_allowlisted');
            Cache::put(self::rejectionKey($device), ['ip' => $ip, 'at' => now()->toIso8601String()], now()->addWeek());

            return null;
        }

        return $device;
    }

    /**
     * The server address to enter on the terminal: the organisation's
     * subdomain, else its verified custom domain, else the shared host where
     * the deployment serves neither.
     */
    public static function serverAddress(?Tenant $tenant): string
    {
        return self::ownAddresses($tenant)[0]
            ?? (string) parse_url((string) config('app.url'), PHP_URL_HOST);
    }

    private static function arrivedOnOwnAddress(Device $device, ?string $host): bool
    {
        $own = self::ownAddresses(Tenant::query()->find($device->tenant_id));

        return $own === [] || in_array(Tenant::normaliseDomain($host), $own, true);
    }

    /**
     * Hosts that name this organisation alone, subdomain first: the subdomain
     * is served by the platform's wildcard, while a custom domain also needs
     * the hosting set up for it.
     *
     * @return list<string>
     */
    private static function ownAddresses(?Tenant $tenant): array
    {
        if ($tenant === null || InstallMode::single()) {
            return [];
        }

        $addresses = [];
        if (Tenant::subdomainsServed()) {
            $addresses[] = $tenant->subdomain.'.'.TenancyDomain::root();
        }
        $custom = Tenant::normaliseDomain($tenant->custom_domain);
        if ($custom !== null && $tenant->custom_domain_verified_at !== null) {
            $addresses[] = $custom;
        }

        return $addresses;
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
