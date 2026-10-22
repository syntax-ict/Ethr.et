<?php

declare(strict_types=1);

use App\Enums\ApproverKind;
use App\Enums\LeaveStatus;
use App\Enums\UserRole;
use App\Jobs\SendApprovalRemindersJob;
use App\Models\ApprovalWorkflow;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\ApprovalEscalatedNotification;
use App\Notifications\ApprovalReminderNotification;
use App\Services\CurrentTenant;
use Illuminate\Support\Facades\Notification;

/**
 * Reminders and escalation on approval chains (docs/APPROVAL-ENGINE-DESIGN.md
 * §6). On a chain the reminder goes to whoever can decide the current step,
 * timed from when the step began; a step that names one person and waits past
 * its limit is told to that person's supervisor, else HR, once.
 */

/** @param  list<array{0: ApproverKind, 1?: string}>  $steps */
function chainReminderWorkflow(Tenant $tenant, array $steps): void
{
    $workflow = ApprovalWorkflow::create(['tenant_id' => $tenant->id, 'request_type' => 'leave', 'name' => 'Chain']);
    foreach ($steps as $i => $step) {
        $workflow->steps()->create([
            'tenant_id' => $tenant->id,
            'position' => $i + 1,
            'approver_kind' => $step[0],
            'approver_ref' => $step[1] ?? null,
        ]);
    }
}

/** @return array{tenant: Tenant, employee: Employee, supervisor: User, hr: User} */
function chainReminderOrg(): array
{
    $tenant = createTenant();
    $supervisorEmployee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $supervisor = createUser(['role' => UserRole::SUPERVISOR, 'employee_id' => $supervisorEmployee->id], $tenant);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'supervisor_id' => $supervisorEmployee->id]);
    $hr = createUser(['role' => UserRole::HR_ADMIN], $tenant);

    return compact('tenant', 'employee', 'supervisor', 'hr');
}

function chainReminderLeave(Tenant $tenant, Employee $employee): LeaveRequest
{
    return LeaveRequest::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'leave_type_id' => LeaveType::factory()->create(['tenant_id' => $tenant->id])->id,
        'days' => 1,
        'status' => LeaveStatus::PENDING,
    ]);
}

function chainReminderRun(Tenant $tenant): void
{
    (new SendApprovalRemindersJob($tenant->id))->handle(app(CurrentTenant::class));
}

test('on a chain the reminder goes to the current step\'s approvers, not the supervisor', function () {
    Notification::fake();
    $org = chainReminderOrg();
    chainReminderWorkflow($org['tenant'], [[ApproverKind::ROLE, 'hr_admin']]);

    $this->travelTo(now()->subHours(72));
    chainReminderLeave($org['tenant'], $org['employee']);
    $this->travelBack();

    chainReminderRun($org['tenant']);

    Notification::assertSentTo($org['hr'], ApprovalReminderNotification::class);
    Notification::assertNotSentTo($org['supervisor'], ApprovalReminderNotification::class);
});

test('a step that began recently is not chased, however old the request', function () {
    Notification::fake();
    $org = chainReminderOrg();
    chainReminderWorkflow($org['tenant'], [[ApproverKind::SUPERVISOR], [ApproverKind::ROLE, 'hr_admin']]);

    $this->travelTo(now()->subHours(72));
    $leave = chainReminderLeave($org['tenant'], $org['employee']);
    $this->travelBack();

    // The supervisor approved step 1 an hour ago; HR has only had it since.
    $this->travelTo(now()->subHour());
    $this->actingAs($org['supervisor'])
        ->putJson("http://{$org['tenant']->subdomain}.ethr.test/api/v1/leave/{$leave->public_id}/approve")
        ->assertOk()
        ->assertJsonPath('status', 'pending');
    $this->travelBack();

    chainReminderRun($org['tenant']);

    Notification::assertNotSentTo($org['hr'], ApprovalReminderNotification::class);
    Notification::assertNotSentTo($org['supervisor'], ApprovalReminderNotification::class);
});

test('an overdue step naming one person is escalated to that person\'s supervisor, once', function () {
    Notification::fake();
    $org = chainReminderOrg();
    $bossEmployee = Employee::factory()->create(['tenant_id' => $org['tenant']->id]);
    $boss = createUser(['role' => UserRole::SUPERVISOR, 'employee_id' => $bossEmployee->id], $org['tenant']);
    $org['supervisor']->employee->update(['supervisor_id' => $bossEmployee->id]);
    chainReminderWorkflow($org['tenant'], [[ApproverKind::SUPERVISOR]]);

    $this->travelTo(now()->subHours(50));
    $leave = chainReminderLeave($org['tenant'], $org['employee']);
    $this->travelBack();

    chainReminderRun($org['tenant']);

    Notification::assertSentTo($boss, ApprovalEscalatedNotification::class);
    Notification::assertNotSentTo($org['hr'], ApprovalEscalatedNotification::class);
    expect($leave->approvalInstance()->firstOrFail()->actions()->pluck('action')->all())->toBe(['escalate']);

    chainReminderRun($org['tenant']);
    Notification::assertSentToTimes($boss, ApprovalEscalatedNotification::class, 1);
    expect($leave->refresh()->status)->toBe(LeaveStatus::PENDING);
});

test('an overdue approver with no supervisor is escalated to HR', function () {
    Notification::fake();
    $org = chainReminderOrg();
    chainReminderWorkflow($org['tenant'], [[ApproverKind::SUPERVISOR]]);

    $this->travelTo(now()->subHours(50));
    chainReminderLeave($org['tenant'], $org['employee']);
    $this->travelBack();

    chainReminderRun($org['tenant']);

    Notification::assertSentTo($org['hr'], ApprovalEscalatedNotification::class);
});

test('a step inside its time limit is not escalated', function () {
    Notification::fake();
    $org = chainReminderOrg();
    chainReminderWorkflow($org['tenant'], [[ApproverKind::SUPERVISOR]]);

    $this->travelTo(now()->subHours(20));
    $leave = chainReminderLeave($org['tenant'], $org['employee']);
    $this->travelBack();

    chainReminderRun($org['tenant']);

    Notification::assertNothingSentTo($org['hr']);
    expect($leave->approvalInstance()->firstOrFail()->actions()->count())->toBe(0);
});

test('without a chain, reminders reach the supervisor as before and nothing escalates', function () {
    Notification::fake();
    $org = chainReminderOrg();

    $this->travelTo(now()->subHours(72));
    chainReminderLeave($org['tenant'], $org['employee']);
    $this->travelBack();

    chainReminderRun($org['tenant']);

    Notification::assertSentTo($org['supervisor'], ApprovalReminderNotification::class);
    Notification::assertNotSentTo($org['hr'], ApprovalEscalatedNotification::class);
});
