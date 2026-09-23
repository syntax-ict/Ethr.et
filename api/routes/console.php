<?php

use App\Jobs\AccrueLeaveBalancesJob;
use App\Jobs\CarryForwardLeaveBalancesJob;
use App\Jobs\CleanupExpiredDataJob;
use App\Jobs\GenerateMonthlyInvoicesJob;
use App\Jobs\HandleOverdueInvoicesJob;
use App\Jobs\NotifyExpiringTrialsJob;
use App\Jobs\RunDashboardDigestsJob;
use App\Jobs\RunScheduledReportsJob;
use App\Jobs\ScanAttendanceAnomaliesJob;
use App\Jobs\ScanMissingPunchesJob;
use App\Jobs\SendApprovalRemindersJob;
use App\Models\Tenant;
use App\Services\Observability\QueueHealth;
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

// Accrue monthly leave entitlement on the 1st of each month — 00:30 UTC (03:30 EAT)
Schedule::call(function () {
    Tenant::where('status', 'active')->each(function (Tenant $tenant) {
        AccrueLeaveBalancesJob::dispatch($tenant->id)->onQueue('default');
    });
})->monthlyOn(1, '00:30')->name('accrue-leave-balances')->withoutOverlapping();

// Carry unused leave into the new balance year on 1 January — 01:00 UTC (04:00 EAT).
// Runs before that month's accrual has any effect on the new year's rows.
Schedule::call(function () {
    $toYear = now()->year;
    Tenant::where('status', 'active')->each(function (Tenant $tenant) use ($toYear) {
        CarryForwardLeaveBalancesJob::dispatch($tenant->id, $toYear - 1, $toYear)
            ->onQueue('default');
    });
})->yearlyOn(1, 1, '01:00')->name('carry-forward-leave-balances')->withoutOverlapping();

// Scan the previous workday for attendance anomalies — 15:45 UTC, just after the
// missing-punch scan, so both read a settled day.
Schedule::call(function () {
    $yesterday = now()->subDay()->format('Y-m-d');
    Tenant::where('status', 'active')->each(function (Tenant $tenant) use ($yesterday) {
        ScanAttendanceAnomaliesJob::dispatch($tenant->id, $yesterday)->onQueue('attendance');
    });
})->dailyAt('15:45')->name('scan-attendance-anomalies')->withoutOverlapping();

// Warn tenant admins at 30/7/1 days before trial expiry — 05:00 UTC (08:00 EAT)
Schedule::job(new NotifyExpiringTrialsJob, 'notifications')
    ->dailyAt('05:00')->name('notify-expiring-trials');

// Deliver scheduled reports. Hourly rather than daily so weekly/monthly schedules
// land near their configured time instead of whenever a single daily tick fires.
Schedule::job(new RunScheduledReportsJob, 'exports')
    ->hourly()->name('run-scheduled-reports')->withoutOverlapping();

// Deliver dashboard digests — same hourly-not-daily reasoning as scheduled reports.
Schedule::job(new RunDashboardDigestsJob, 'exports')
    ->hourly()->name('run-dashboard-digests')->withoutOverlapping();

// Remind approvers about requests waiting longer than 48h — 06:00 UTC (09:00 EAT),
// i.e. the start of the Ethiopian working day rather than overnight.
Schedule::call(function () {
    Tenant::where('status', 'active')->each(function (Tenant $tenant) {
        SendApprovalRemindersJob::dispatch($tenant->id)->onQueue('notifications');
    });
})->dailyAt('06:00')->name('send-approval-reminders')->withoutOverlapping();

// Generate monthly invoices on the 1st of each month at 03:00 UTC (06:00 EAT)
Schedule::job(new GenerateMonthlyInvoicesJob)->monthlyOn(1, '03:00');

// Handle overdue invoices daily at 04:00 UTC (07:00 EAT)
Schedule::job(new HandleOverdueInvoicesJob)->dailyAt('04:00');

// Cleanup expired data daily at 02:00 UTC (05:00 EAT)
// Notifications: 90 days, Webhook deliveries: 30 days, Import staging: 7 days
Schedule::job(new CleanupExpiredDataJob)->dailyAt('02:00');

// Full backup daily at 01:00 UTC (04:00 EAT) — before the 02:00 cleanup, so a
// backup always exists from before data was pruned rather than after.
//
// --off-host is not set here on purpose. Off-host credentials may not exist
// yet, and a scheduled task that fails every night is a scheduled task people
// mute. Add it to this line once the disk is configured; until then the command
// warns on every run that the backup only exists on the host it protects.
//
// On shared hosting this arrives via one Plesk Scheduled Task running
// `artisan schedule:run` every minute, not via a daemon. See
// docs/deployment/BACKUP-RESTORE.md.
// No --keep here on purpose: the command falls back to config('backup.keep'),
// so BACKUP_KEEP governs retention per environment. Passing it here would
// override the env var on every host and re-create the defect where lowering
// retention on a 5 GB plan had no effect. See config/backup.php.
Schedule::command('ethr:backup')
    ->dailyAt('01:00')
    ->name('ethr-backup')
    ->withoutOverlapping();

// Scheduler heartbeat — the cheapest entry here and the one that makes the rest
// observable. Everything asynchronous in ETHR arrives through a single Plesk
// Scheduled Task running `schedule:run`; if that stops, jobs stop and nothing
// says so. This records that the scheduler ran, so `ethr:queue:check` and the
// health endpoint can tell "quiet" from "dead".
//
// Deliberately not ->withoutOverlapping(): that takes a cache lock, and a lock
// left behind by a killed run would suppress the very signal this exists to
// emit.
Schedule::call(fn () => app(QueueHealth::class)->beat())
    ->everyMinute()
    ->name('scheduler-heartbeat');
