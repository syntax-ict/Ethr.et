<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\AttendanceRecorded;
use App\Events\PayrollProcessed;
use App\Services\Dashboard\DashboardCacheVersion;

class InvalidateDashboardCache
{
    public function handleAttendance(AttendanceRecorded $event): void
    {
        DashboardCacheVersion::bump($event->record->tenant_id);
    }

    public function handlePayroll(PayrollProcessed $event): void
    {
        DashboardCacheVersion::bump($event->payrollRun->tenant_id);
    }
}
