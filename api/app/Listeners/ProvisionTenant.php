<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\TenantCreated;
use Illuminate\Support\Facades\Log;

class ProvisionTenant
{
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
    }
}
