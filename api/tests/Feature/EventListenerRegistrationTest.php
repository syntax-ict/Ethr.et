<?php

declare(strict_types=1);

use App\Enums\UserRole;
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
 * DEFECT (both todos below): Laravel 12 auto-discovers listeners in
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
})->todo(note: 'DEFECT: listeners are both auto-discovered and registered in AppServiceProvider:143-149, so each runs twice per event');

it('delivers one payroll-processed notice and one payslip notice per dispatch', function () {
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
    Notification::assertSentToTimes($staff, PayslipAvailableNotification::class, 1);
})->todo(note: 'DEFECT: NotifyPayrollProcessed is registered twice (discovery + AppServiceProvider:149), so every payslip notice is sent twice');
