<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Events\PayrollApproved;
use App\Events\PayrollProcessed;
use App\Events\TenantCreated;
use App\Models\Employee;
use App\Models\PayrollEntry;
use App\Models\PayrollRun;
use App\Notifications\PayrollProcessedNotification;
use App\Notifications\PayslipAvailableNotification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/*
 * Every listener test in the suite calls `(new Listener)->handle($event)`
 * directly, so none of them could see how many times a real `event()` call
 * runs a listener. These dispatch the event and count what comes out.
 *
 * FIXED 2026-10-01 (bootstrap/app.php withEvents(discover: false)). Was: Laravel 12 auto-discovers listeners in
 * app/Listeners from their handle() type-hint, and
 * AppServiceProvider::boot() (app/Providers/AppServiceProvider.php:143-149)
 * ALSO registers the same classes with Event::listen(). `php artisan
 * event:list` shows each one twice — e.g. `App\Listeners\ProvisionTenant` and
 * `App\Listeners\ProvisionTenant@handle` under TenantCreated, and the same
 * pair for NotifyPayrollProcessed, NotifyDeviceOffline, NotifyDeviceSyncFailed,
 * NotifyPayrollRunFailed and both InvalidateDashboardCache handlers. Every one
 * runs twice per event: every payslip notification and every
 * device/payroll alert is delivered twice, including the mail channel.
 */

it('runs ProvisionTenant once per TenantCreated', function () {
    $tenant = createTenant();
    $admin = createUser([], $tenant);

    Log::spy();

    event(new TenantCreated($tenant, $admin));

    Log::shouldHaveReceived('info')
        ->withArgs(fn (string $message) => $message === 'Provisioning tenant')
        ->once();
});

it('delivers one payroll-processed notice per dispatch, and one payslip notice per approval', function () {
    Notification::fake();

    $tenant = createTenant();
    $finance = createUser(['role' => UserRole::FINANCE_ADMIN], $tenant);

    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $staff = createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant);

    $run = PayrollRun::factory()->create(['tenant_id' => $tenant->id]);
    PayrollEntry::factory()->create([
        'tenant_id' => $tenant->id,
        'payroll_run_id' => $run->id,
        'employee_id' => $employee->id,
    ]);

    // QUEUE_CONNECTION=sync in phpunit.xml, so the queued listener runs here.
    event(new PayrollProcessed($run));

    Notification::assertSentToTimes($finance, PayrollProcessedNotification::class, 1);
    // Payslips are released at approval since 686879a, not at processing.
    Notification::assertSentToTimes($staff, PayslipAvailableNotification::class, 0);

    event(new PayrollApproved($run->fresh()));

    Notification::assertSentToTimes($staff, PayslipAvailableNotification::class, 1);
});

it('registers each application listener once per event', function () {
    $duplicates = [];
    foreach (app('events')->getRawListeners() as $event => $listeners) {
        if (! str_starts_with($event, 'App\\')) {
            continue;
        }
        $names = array_map(
            fn ($listener) => is_array($listener) ? implode('@', $listener) : (is_string($listener) ? preg_replace('/@handle$/', '', $listener) : 'closure'),
            $listeners,
        );
        foreach (array_count_values(array_filter($names, fn ($n) => $n !== 'closure')) as $name => $count) {
            if ($count > 1) {
                $duplicates[] = "{$event}: {$name} x{$count}";
            }
        }
    }

    expect($duplicates)->toBe([]);
});
