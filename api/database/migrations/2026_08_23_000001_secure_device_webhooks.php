<?php

declare(strict_types=1);

use App\Models\Device;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Harden biometric device webhooks (audit finding F-1).
     *
     * Before this, `DeviceController::resolveWebhookDevice()` authenticated a
     * webhook by `webhook_token` OR, when no token was sent, by the device's
     * `serial_number` alone. Serials are printed on the hardware, enumerable,
     * and not unique in the schema — so anyone who knew a serial could inject
     * attendance (which feeds payroll) for that tenant without authenticating.
     *
     * The policy is now "token where possible, IP allowlist for legacy devices
     * that cannot send one":
     *   - a device with a webhook_token must present it;
     *   - a token-less device may only be reached from an allowlisted source IP.
     *
     * This migration adds the per-device allowlist column and backfills a
     * webhook_token for every device that lacks one, so no existing device is
     * left on the old serial-only path. Operators must configure the token on
     * the device, or set webhook_ip_allowlist for firmware that cannot.
     */
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table): void {
            $table->json('webhook_ip_allowlist')->nullable()->after('webhook_token');
        });

        Device::withoutGlobalScopes()
            ->whereNull('webhook_token')
            ->orderBy('id')
            ->chunkById(200, function ($devices): void {
                foreach ($devices as $device) {
                    $device->forceFill([
                        'webhook_token' => Device::generateWebhookToken(),
                    ])->saveQuietly();
                }
            });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table): void {
            $table->dropColumn('webhook_ip_allowlist');
        });
    }
};
