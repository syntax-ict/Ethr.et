<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Jobs\SendApprovalRemindersJob;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\ProfileUpdateRequest;
use App\Notifications\ApprovalReminderNotification;
use App\Services\CurrentTenant;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Notification;

/**
 * `ApprovalReminderNotification` shipped in Phase 5 with a mail template, a
 * preference toggle and no caller at all — nothing in the codebase ever
 * constructed it, so the 48-hour reminder in S26 could not fire. These tests
 * cover the job that now dispatches it.
 */
function reminderFixture(): array
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

    return [$tenant, $employee, $supervisor];
}

test('a leave request older than 48 hours reminds the supervisor', function () {
    Notification::fake();

    [$tenant, $employee, $supervisor] = reminderFixture();
    $leaveType = LeaveType::factory()->create(['tenant_id' => $tenant->id]);

    LeaveRequest::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
        'created_at' => now()->subHours(72),
    ]);

    (new SendApprovalRemindersJob($tenant->id))->handle(app(CurrentTenant::class));

    Notification::assertSentTo($supervisor, ApprovalReminderNotification::class);
});

test('a leave request inside the 48-hour window is not chased', function () {
    Notification::fake();

    [$tenant, $employee, $supervisor] = reminderFixture();
    $leaveType = LeaveType::factory()->create(['tenant_id' => $tenant->id]);

    LeaveRequest::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
        'created_at' => now()->subHours(12),
    ]);

    (new SendApprovalRemindersJob($tenant->id))->handle(app(CurrentTenant::class));

    Notification::assertNothingSent();
});

test('an approver with several stale items receives one reminder, not one per item', function () {
    Notification::fake();

    [$tenant, $employee, $supervisor] = reminderFixture();
    $leaveType = LeaveType::factory()->create(['tenant_id' => $tenant->id]);

    LeaveRequest::factory()->count(3)->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
        'created_at' => now()->subHours(72),
    ]);

    (new SendApprovalRemindersJob($tenant->id))->handle(app(CurrentTenant::class));

    Notification::assertSentToTimes($supervisor, ApprovalReminderNotification::class, 1);
});

test('a stale profile update request reminds the HR reviewer', function () {
    Notification::fake();

    [$tenant, $employee] = reminderFixture();
    $hr = createUser(['role' => UserRole::HR_ADMIN], $tenant);

    ProfileUpdateRequest::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'created_at' => now()->subHours(72),
    ]);

    (new SendApprovalRemindersJob($tenant->id))->handle(app(CurrentTenant::class));

    Notification::assertSentTo($hr, ApprovalReminderNotification::class);
});

test('an already-approved request is never chased', function () {
    Notification::fake();

    [$tenant, $employee] = reminderFixture();
    createUser(['role' => UserRole::HR_ADMIN], $tenant);

    ProfileUpdateRequest::factory()->approved()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'created_at' => now()->subHours(72),
    ]);

    (new SendApprovalRemindersJob($tenant->id))->handle(app(CurrentTenant::class));

    Notification::assertNothingSent();
});

test('the job is scheduled daily', function () {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($e) => $e->description === 'send-approval-reminders');

    expect($events)->toHaveCount(1);
    expect($events->first()->expression)->toBe('0 6 * * *');
});
