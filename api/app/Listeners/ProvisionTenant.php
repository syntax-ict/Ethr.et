<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\TenantCreated;
use App\Services\Attendance\AttendanceBaseline;
use Illuminate\Support\Facades\Log;

class ProvisionTenant
{
    public function __construct(private readonly AttendanceBaseline $baseline) {}

    public function handle(TenantCreated $event): void
    {
        $tenant = $event->tenant;

        Log::info('Provisioning tenant', [
            'tenant_id' => $tenant->public_id,
            'subdomain' => $tenant->subdomain,
        ]);

        $tenant->update([
            'settings' => array_merge($tenant->settings ?? [], [
                'timezone' => 'Africa/Addis_Ababa',
                'locale' => 'en',
                'calendar' => 'ethiopian',
            ]),
        ]);

        // Attendance works from the first punch: the industry's usual
        // schedule, working week and holidays, before anyone opens a
        // settings page.
        $this->baseline->ensure($tenant);
    }
}
