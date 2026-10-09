<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Events\PayrollApproved;
use App\Events\PayrollProcessed;
use App\Listeners\NotifyPayrollProcessed;
use App\Listeners\NotifyPayslipsReleased;
use App\Models\Employee;
use App\Models\PayrollEntry;
use App\Models\PayrollRun;
use App\Notifications\PayrollProcessedNotification;
use App\Notifications\PayslipAvailableNotification;
use App\Services\CurrentTenant;
use Illuminate\Support\Facades\Notification;

/**
 * Payslips are released when a run is approved, not when it is calculated.
 *
 * Employees were told "your payslip is available", with their net pay, the
 * moment a run finished calculating — before finance approved it — and
 * /payslips/my listed those unapproved payslips. A run voided and reprocessed
 * told the same people a second, different figure. And the one person a
 * payslip is for could not download its PDF: the route required payroll.viewAll.
 */
function payslipFixture(string $runStatus): array
{
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $staff = createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant);
    $employee->update(['user_id' => $staff->id]);
    $run = PayrollRun::factory()->create(['tenant_id' => $tenant->id, 'status' => $runStatus]);
    $entry = PayrollEntry::factory()->create([
        'tenant_id' => $tenant->id,
        'payroll_run_id' => $run->id,
        'employee_id' => $employee->id,
    ]);

    return [$tenant, $staff, $run, $entry];
}

test('processing tells finance, not the employees', function () {
    Notification::fake();
    [$tenant, $staff, $run] = payslipFixture('completed');
    app(CurrentTenant::class)->set($tenant);
    $finance = createUser(['role' => UserRole::FINANCE_ADMIN], $tenant);

    (new NotifyPayrollProcessed)->handle(new PayrollProcessed($run));

    Notification::assertSentTo($finance, PayrollProcessedNotification::class);
    Notification::assertNotSentTo($staff, PayslipAvailableNotification::class);
});

test('approving a run releases each payslip to its employee', function () {
    Notification::fake();
    [$tenant, $staff, $run] = payslipFixture('completed');
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    test()->putJson("http://{$tenant->subdomain}.ethr.test/api/v1/payroll/runs/{$run->public_id}/approve")
        ->assertOk();

    Notification::assertSentTo($staff, PayslipAvailableNotification::class);
});

test('the release listener works from a queue worker with no tenant resolved', function () {
    Notification::fake();
    [, $staff, $run] = payslipFixture('approved');
    app(CurrentTenant::class)->forget();

    (new NotifyPayslipsReleased)->handle(new PayrollApproved($run->fresh()));

    Notification::assertSentTo($staff, PayslipAvailableNotification::class);
});

test('an employee sees only payslips from approved runs', function (string $status, int $visible) {
    [$tenant, $staff] = payslipFixture($status);
    test()->actingAs($staff);

    test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/payroll/payslips/my")
        ->assertOk()
        ->assertJsonCount($visible, 'data');
})->with([
    'approved' => ['approved', 1],
    'calculated, awaiting approval' => ['completed', 0],
    'voided' => ['voided', 0],
]);

test('an employee can download their own approved payslip', function () {
    [$tenant, $staff, , $entry] = payslipFixture('approved');
    test()->actingAs($staff);

    $response = test()->get("http://{$tenant->subdomain}.ethr.test/api/v1/payroll/payslips/{$entry->public_id}/pdf");

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toContain('application/pdf');
});

test('an employee cannot download a payslip before approval', function () {
    [$tenant, $staff, , $entry] = payslipFixture('completed');
    test()->actingAs($staff);

    test()->get("http://{$tenant->subdomain}.ethr.test/api/v1/payroll/payslips/{$entry->public_id}/pdf")
        ->assertForbidden();
});

test('an employee cannot download a colleague\'s payslip', function () {
    [$tenant, , , $entry] = payslipFixture('approved');
    $colleague = createUser([
        'role' => UserRole::EMPLOYEE,
        'employee_id' => Employee::factory()->create(['tenant_id' => $tenant->id])->id,
    ], $tenant);
    test()->actingAs($colleague);

    test()->get("http://{$tenant->subdomain}.ethr.test/api/v1/payroll/payslips/{$entry->public_id}/pdf")
        ->assertForbidden();
});

test("an employee's payslip names its period", function () {
    // PayrollEntryResource never sent the run's period, so My Payslips had no
    // way to tell one month's payslip from the next (found by audit F3).
    [$tenant, $staff, $run] = payslipFixture('approved');
    test()->actingAs($staff);

    test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/payroll/payslips/my")
        ->assertOk()
        ->assertJsonPath('data.0.period_label', $run->period_label);

    expect($run->period_label)->toBeString()->not->toBe('');
});

test("the employee dashboard's latest payslip is the latest approved one", function (string $status, bool $shown) {
    // Found browser-testing N13: /payslips/my lists approved runs only (N8),
    // but the dashboard tile took the newest entry with no status check, so
    // an employee saw a calculated run's net pay before finance approved it.
    [$tenant, $staff, $run, $entry] = payslipFixture($status);
    test()->actingAs($staff);

    $payslip = test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/dashboard/employee")
        ->assertOk()
        ->json('latest_payslip');

    expect($payslip === null ? null : $payslip['net_cents'])->toBe($shown ? $entry->net_cents : null);
})->with([
    'approved' => ['approved', true],
    'calculated, awaiting approval' => ['completed', false],
    'voided' => ['voided', false],
]);
