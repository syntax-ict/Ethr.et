<?php

use App\Jobs\ScanMissingPunchesJob;
use App\Models\Tenant;
use Illuminate\Support\Facades\Schedule;

// Pull events from all registered biometric devices every 5 minutes
Schedule::command('devices:sync')->everyFiveMinutes()->withoutOverlapping();

// Scan previous workday for missing punches — notify employee + supervisor
// 18:30 EAT = 15:30 UTC (after the standard Ethiopian workday ends at 17:30)
Schedule::call(function () {
    $yesterday = now()->subDay()->format('Y-m-d');

    Tenant::where('status', 'active')->each(function (Tenant $tenant) use ($yesterday) {
        ScanMissingPunchesJob::dispatch($tenant->id, $yesterday)
            ->onQueue('attendance');
    });
})->dailyAt('15:30')->name('scan-missing-punches')->withoutOverlapping();
