<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Admin\TenantJourney\JourneyCalculator;
use Illuminate\Console\Command;

/**
 * Keeps the console's journey snapshots current (docs/operations/TENANT-JOURNEY.md).
 *
 * Scheduled hourly: it refreshes only the organisations whose snapshot is
 * missing or older than fifty minutes, so the first run after a deploy fills
 * the list and later runs only keep it fresh.
 */
class RefreshTenantJourneysCommand extends Command
{
    protected $signature = 'ethr:refresh-tenant-journeys
        {--tenant= : Refresh one organisation, by its public id}
        {--all : Refresh every organisation, not only the stale ones}';

    protected $description = 'Recompute where each organisation is between sign-up and using ETHR';

    public function handle(JourneyCalculator $calculator): int
    {
        $publicId = $this->option('tenant');

        if (is_string($publicId) && $publicId !== '') {
            $tenant = Tenant::withoutGlobalScopes()->where('public_id', $publicId)->first();

            if (! $tenant instanceof Tenant) {
                $this->error("No organisation with public id {$publicId}.");

                return self::FAILURE;
            }

            $snapshot = $calculator->refresh($tenant);
            $this->info("{$tenant->name}: {$snapshot->stage}, {$snapshot->health_state}.");

            return self::SUCCESS;
        }

        $done = $calculator->refreshStale(
            limit: 1000,
            maxAgeMinutes: $this->option('all') ? 0 : 50,
        );
        $this->info("Refreshed {$done} organisation(s).");

        return self::SUCCESS;
    }
}
