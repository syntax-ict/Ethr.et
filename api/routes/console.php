<?php

use App\Jobs\CleanupExpiredDataJob;
use App\Jobs\GenerateMonthlyInvoicesJob;
use App\Jobs\HandleOverdueInvoicesJob;
use App\Jobs\ScanMissingPunchesJob;
use App\Models\Tenant;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Pull events from all registered biometric devices every 5 minutes
Schedule::command('devices:sync')->everyFiveMinutes()->withoutOverlapping();

// Scan previous workday for missing punches — 18:30 EAT = 15:30 UTC
Schedule::call(function () {
    $yesterday = now()->subDay()->format('Y-m-d');
    Tenant::where('status', 'active')->each(function (Tenant $tenant) use ($yesterday) {
        ScanMissingPunchesJob::dispatch($tenant->id, $yesterday)->onQueue('attendance');
    });
})->dailyAt('15:30')->name('scan-missing-punches')->withoutOverlapping();

// Generate monthly invoices on the 1st of each month at 03:00 UTC (06:00 EAT)
Schedule::job(new GenerateMonthlyInvoicesJob)->monthlyOn(1, '03:00');

// Handle overdue invoices daily at 04:00 UTC (07:00 EAT)
Schedule::job(new HandleOverdueInvoicesJob)->dailyAt('04:00');

// Cleanup expired data daily at 02:00 UTC (05:00 EAT)
// Notifications: 90 days, Webhook deliveries: 30 days, Import staging: 7 days
Schedule::job(new CleanupExpiredDataJob)->dailyAt('02:00');
