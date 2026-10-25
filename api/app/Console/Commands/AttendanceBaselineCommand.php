<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Attendance\AttendanceBaseline;
use Illuminate\Console\Command;
use Throwable;

/**
 * Gives every operational organisation a working attendance setup
 * (AttendanceBaseline): one with no shift gets its industry's usual schedule,
 * one whose default shift was lost gets it back.
 *
 * Sign-up does this itself (ProvisionTenant). This catches everyone else:
 * organisations from before 2026-10-24, ones created from the platform
 * console, and any that lost their default. Hourly and idempotent, so the
 * first run after a deploy is the backfill and later runs change nothing.
 */
class AttendanceBaselineCommand extends Command
{
    protected $signature = 'attendance:baseline';

    protected $description = 'Give every organisation a default schedule, working week and holidays when it has none';

    public function handle(AttendanceBaseline $baseline): int
    {
        $counts = ['provisioned' => 0, 'healed' => 0];

        Tenant::operational()->each(function (Tenant $tenant) use ($baseline, &$counts): void {
            try {
                $outcome = $baseline->ensure($tenant);
                if (isset($counts[$outcome])) {
                    $counts[$outcome]++;
                }
            } catch (Throwable $e) {
                report($e);
                $this->error("Tenant {$tenant->public_id}: {$e->getMessage()}");
            }
        });

        $this->info("Attendance baseline: {$counts['provisioned']} provisioned, {$counts['healed']} healed.");

        return self::SUCCESS;
    }
}
