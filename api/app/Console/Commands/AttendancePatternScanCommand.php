<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Attendance\AttendancePatternScan;
use App\Services\CurrentTenant;
use Illuminate\Console\Command;
use Throwable;

/**
 * Files the day's cross-punch findings (AttendancePatternScan) for every
 * operational organisation. Daily, idempotent.
 */
class AttendancePatternScanCommand extends Command
{
    protected $signature = 'attendance:pattern-scan';

    protected $description = 'File attendance patterns no single punch shows (shared phones, arrivals on the grace edge)';

    public function handle(CurrentTenant $currentTenant, AttendancePatternScan $scan): int
    {
        $totals = ['buddy' => 0, 'grace_edge' => 0];

        Tenant::operational()->each(function (Tenant $tenant) use ($currentTenant, $scan, &$totals): void {
            $currentTenant->set($tenant);
            try {
                foreach ($scan->run($tenant) as $kind => $count) {
                    $totals[$kind] += $count;
                }
            } catch (Throwable $e) {
                report($e);
            } finally {
                $currentTenant->forget();
            }
        });

        $this->info("Pattern scan: {$totals['buddy']} shared-phone, {$totals['grace_edge']} grace-edge finding(s).");

        return self::SUCCESS;
    }
}
