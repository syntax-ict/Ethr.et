<?php

declare(strict_types=1);

use App\Enums\TenantStatus;
use App\Jobs\AccrueLeaveBalancesJob;
use App\Jobs\CarryForwardLeaveBalancesJob;
use App\Jobs\ScanAttendanceAnomaliesJob;
use App\Jobs\ScanMissingPunchesJob;
use App\Jobs\SendApprovalRemindersJob;
use App\Models\Tenant;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Queue;

/**
 * The five per-tenant sweeps in routes/console.php dispatch one job per tenant
 * that is in use. They selected `Tenant::where('status', 'active')`, which
 * skips every tenant still on its trial. Sign-up creates tenants as `trial`
 * for six months (AuthService), and a trial tenant is fully operational
 * everywhere else: Tenant::isActive() counts it, and PlanFeatureService and
 * PlanLimitService exempt it. So for a new customer's first six months, no
 * missing punch was flagged, no anomaly scanned, no approver reminded, and no
 * leave accrued until conversion.
 *
 * They now select Tenant::operational(), the query form of isActive(): active,
 * or on a trial that has not expired. An expired trial, a suspended tenant and
 * a cancelled one are still skipped, as before.
 */
function runTenantSweep(string $name): void
{
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event) => $event->description === $name);

    expect($event)->toBeInstanceOf(CallbackEvent::class, "no scheduled callback named {$name}");

    $event->run(app());
}

dataset('tenant sweeps', [
    'scan-missing-punches' => ['scan-missing-punches', ScanMissingPunchesJob::class],
    'accrue-leave-balances' => ['accrue-leave-balances', AccrueLeaveBalancesJob::class],
    'carry-forward-leave-balances' => ['carry-forward-leave-balances', CarryForwardLeaveBalancesJob::class],
    'scan-attendance-anomalies' => ['scan-attendance-anomalies', ScanAttendanceAnomaliesJob::class],
    'send-approval-reminders' => ['send-approval-reminders', SendApprovalRemindersJob::class],
]);

test('a tenant sweep reaches active tenants and live trials, and nothing else', function (string $name, string $job) {
    Queue::fake();

    $active = Tenant::factory()->create();
    $trial = Tenant::factory()->trial()->create();
    $openEndedTrial = Tenant::factory()->create(['status' => TenantStatus::TRIAL, 'trial_ends_at' => null]);
    $expiredTrial = Tenant::factory()->expiredTrial()->create();
    $suspended = Tenant::factory()->suspended()->create();
    $cancelled = Tenant::factory()->create(['status' => TenantStatus::CANCELLED]);

    runTenantSweep($name);

    $dispatchedFor = collect(Queue::pushed($job))
        ->map(fn ($pushed) => (new ReflectionProperty($pushed, 'tenantId'))->getValue($pushed))
        ->sort()
        ->values()
        ->all();

    expect($dispatchedFor)->toBe(collect([$active->id, $trial->id, $openEndedTrial->id])->sort()->values()->all());

    // The same set Tenant::isActive() accepts, so the query and the model
    // method cannot disagree about which tenants are in use.
    foreach ([$active, $trial, $openEndedTrial, $expiredTrial, $suspended, $cancelled] as $tenant) {
        expect(in_array($tenant->id, $dispatchedFor, true))->toBe($tenant->isActive());
    }
})->with('tenant sweeps');
