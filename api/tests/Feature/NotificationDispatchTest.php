<?php

declare(strict_types=1);

use App\Enums\LeaveStatus;
use App\Enums\TenantStatus;
use App\Enums\UserRole;
use App\Events\PayrollProcessed;
use App\Jobs\NotifyAnnouncementAudienceJob;
use App\Jobs\NotifyExpiringTrialsJob;
use App\Jobs\ScanAttendanceAnomaliesJob;
use App\Listeners\NotifyPayrollProcessed;
use App\Models\Announcement;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\PayrollEntry;
use App\Models\PayrollRun;
use App\Notifications\AnnouncementNotification;
use App\Notifications\AttendanceAnomalyNotification;
use App\Notifications\LeaveApprovedNotification;
use App\Notifications\LeaveRejectedNotification;
use App\Notifications\LeaveRequestedNotification;
use App\Notifications\PayrollProcessedNotification;
use App\Notifications\PayslipAvailableNotification;
use App\Notifications\TrialExpiringNotification;
use App\Services\Attendance\AttendanceIntelligence;
use App\Services\CurrentTenant;
use Illuminate\Support\Facades\Notification;

/**
 * The audit's critical finding: 8 of 18 notification classes were never
 * constructed anywhere in application code. The classes shipped, tests
 * instantiated some of them directly, and no production path could ever send
 * one — so leave, payroll, payslips, announcements, anomalies and trial expiry
 * were all silent.
 *
 * These tests assert dispatch from the *real trigger points*, not by
 * constructing notifications by hand, which is precisely how the gap stayed
 * invisible.
 */
function leaveChainFixture(): array
{
    $tenant = createTenant();

    $supervisorEmployee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $supervisor = createUser(
        ['role' => UserRole::SUPERVISOR, 'employee_id' => $supervisorEmployee->id],
        $tenant,
    );
    $supervisorEmployee->update(['user_id' => $supervisor->id]);

    $employee = Employee::factory()->create([
        'tenant_id' => $tenant->id,
        'supervisor_id' => $supervisorEmployee->id,
    ]);
    $staff = createUser(
        ['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id],
        $tenant,
    );
    $employee->update(['user_id' => $staff->id]);

    $leaveType = LeaveType::factory()->create(['tenant_id' => $tenant->id]);

    LeaveBalance::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
        'year' => now()->year,
        'entitled_days' => 20,
        'used_days' => 0,
        'pending_days' => 0,
    ]);

    return [$tenant, $employee, $staff, $supervisor, $leaveType];
}

// ── Leave chain (PHASE_04 / PHASE_05 S26) ──

test('requesting leave notifies the approver', function () {
    Notification::fake();

    [$tenant, $employee, $staff, $supervisor, $leaveType] = leaveChainFixture();

    test()->actingAs($staff);

    test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/leave/request", [
        'leave_type_public_id' => $leaveType->public_id,
        'start_date' => now()->addDays(10)->toDateString(),
        'end_date' => now()->addDays(11)->toDateString(),
        'reason' => 'Family matter',
    ])->assertStatus(201);

    Notification::assertSentTo($supervisor, LeaveRequestedNotification::class);
});

test('approving leave notifies the employee', function () {
    Notification::fake();

    [$tenant, $employee, $staff, $supervisor, $leaveType] = leaveChainFixture();

    $leave = LeaveRequest::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
        'status' => LeaveStatus::PENDING,
    ]);

    test()->actingAs($supervisor);

    test()->putJson("http://{$tenant->subdomain}.ethr.test/api/v1/leave/{$leave->public_id}/approve")
        ->assertOk();

    Notification::assertSentTo($staff, LeaveApprovedNotification::class);
});

test('rejecting leave notifies the employee', function () {
    Notification::fake();

    [$tenant, $employee, $staff, $supervisor, $leaveType] = leaveChainFixture();

    $leave = LeaveRequest::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
        'status' => LeaveStatus::PENDING,
    ]);

    test()->actingAs($supervisor);

    test()->putJson(
        "http://{$tenant->subdomain}.ethr.test/api/v1/leave/{$leave->public_id}/reject",
        ['reason' => 'Coverage unavailable'],
    )->assertOk();

    Notification::assertSentTo($staff, LeaveRejectedNotification::class);
});

test('batch approval notifies the employee too', function () {
    Notification::fake();

    [$tenant, $employee, $staff, $supervisor, $leaveType] = leaveChainFixture();

    $leave = LeaveRequest::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
        'status' => LeaveStatus::PENDING,
    ]);

    test()->actingAs($supervisor);

    test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/approvals/batch", [
        'actions' => [['type' => 'leave', 'public_id' => $leave->public_id, 'action' => 'approve']],
    ])->assertOk();

    Notification::assertSentTo($staff, LeaveApprovedNotification::class);
});

// ── Payroll (PHASE_05 S26) ──

test('processing payroll notifies finance and each employee', function () {
    Notification::fake();

    $tenant = createTenant();
    app(CurrentTenant::class)->set($tenant);

    $finance = createUser(['role' => UserRole::FINANCE_ADMIN], $tenant);

    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $staff = createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant);
    $employee->update(['user_id' => $staff->id]);

    $run = PayrollRun::factory()->create(['tenant_id' => $tenant->id]);
    PayrollEntry::factory()->create([
        'tenant_id' => $tenant->id,
        'payroll_run_id' => $run->id,
        'employee_id' => $employee->id,
    ]);

    (new NotifyPayrollProcessed)->handle(new PayrollProcessed($run));

    Notification::assertSentTo($finance, PayrollProcessedNotification::class);
    Notification::assertSentTo($staff, PayslipAvailableNotification::class);
});

// ── Announcements (PHASE_05 S26) ──

test('publishing an announcement notifies the audience', function () {
    Notification::fake();

    $tenant = createTenant();
    app(CurrentTenant::class)->set($tenant);

    $reader = createUser(['role' => UserRole::EMPLOYEE], $tenant);

    $announcement = Announcement::factory()->create([
        'tenant_id' => $tenant->id,
        'target_type' => 'all',
        'published_at' => now(),
    ]);

    (new NotifyAnnouncementAudienceJob($announcement->id))->handle(app(CurrentTenant::class));

    Notification::assertSentTo($reader, AnnouncementNotification::class);
});

test('an announcement scheduled for the future notifies nobody yet', function () {
    Notification::fake();

    $tenant = createTenant();
    app(CurrentTenant::class)->set($tenant);
    createUser(['role' => UserRole::EMPLOYEE], $tenant);

    $announcement = Announcement::factory()->create([
        'tenant_id' => $tenant->id,
        'target_type' => 'all',
        'published_at' => now()->addWeek(),
    ]);

    (new NotifyAnnouncementAudienceJob($announcement->id))->handle(app(CurrentTenant::class));

    Notification::assertNothingSent();
});

test('an announcement does not reach another tenant', function () {
    Notification::fake();

    $tenant = createTenant();
    app(CurrentTenant::class)->set($tenant);

    $otherTenant = createTenant();
    $outsider = createUser(['role' => UserRole::EMPLOYEE], $otherTenant);

    $announcement = Announcement::factory()->create([
        'tenant_id' => $tenant->id,
        'target_type' => 'all',
        'published_at' => now(),
    ]);

    (new NotifyAnnouncementAudienceJob($announcement->id))->handle(app(CurrentTenant::class));

    Notification::assertNotSentTo($outsider, AnnouncementNotification::class);
});

// ── Trial expiry (PHASE_08) ──

test('a trial expiring at a milestone warns the tenant admin', function () {
    Notification::fake();

    $tenant = createTenant([
        'status' => TenantStatus::TRIAL,
        'trial_ends_at' => now()->addDays(7),
    ]);
    $admin = createUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    (new NotifyExpiringTrialsJob)->handle();

    Notification::assertSentTo($admin, TrialExpiringNotification::class);
});

test('a trial away from any milestone is not warned', function () {
    Notification::fake();

    $tenant = createTenant([
        'status' => TenantStatus::TRIAL,
        'trial_ends_at' => now()->addDays(15),
    ]);
    createUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    (new NotifyExpiringTrialsJob)->handle();

    Notification::assertNothingSent();
});

test('the same milestone does not warn twice', function () {
    Notification::fake();

    $tenant = createTenant([
        'status' => TenantStatus::TRIAL,
        'trial_ends_at' => now()->addDays(7),
    ]);
    $admin = createUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    (new NotifyExpiringTrialsJob)->handle();
    (new NotifyExpiringTrialsJob)->handle();

    Notification::assertSentToTimes($admin, TrialExpiringNotification::class, 1);
});

// ── Attendance anomalies (PHASE_05 S26) ──

test('an excessive-hours record notifies the supervisor', function () {
    Notification::fake();

    [$tenant, $employee, , $supervisor] = leaveChainFixture();
    app(CurrentTenant::class)->set($tenant);

    $date = now()->subDay()->toDateString();

    // >16 worked hours trips AttendanceIntelligence::detectAnomalies().
    AttendanceRecord::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'date' => $date,
        'check_in' => now()->subDay()->setTime(4, 0),
        'check_out' => now()->subDay()->setTime(23, 0),
    ]);

    (new ScanAttendanceAnomaliesJob($tenant->id, $date))
        ->handle(app(AttendanceIntelligence::class), app(CurrentTenant::class));

    Notification::assertSentTo($supervisor, AttendanceAnomalyNotification::class);
});

test('a normal working day raises no anomaly', function () {
    Notification::fake();

    [$tenant, $employee] = leaveChainFixture();
    app(CurrentTenant::class)->set($tenant);

    $date = now()->subDay()->toDateString();

    AttendanceRecord::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'date' => $date,
        'check_in' => now()->subDay()->setTime(8, 0),
        'check_out' => now()->subDay()->setTime(17, 0),
    ]);

    (new ScanAttendanceAnomaliesJob($tenant->id, $date))
        ->handle(app(AttendanceIntelligence::class), app(CurrentTenant::class));

    Notification::assertNothingSent();
});
