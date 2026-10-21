<?php

declare(strict_types=1);

use App\Enums\LeaveStatus;
use App\Enums\UserRole;
use App\Models\ApprovalWorkflow;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Tenant;
use App\Notifications\LeaveRequestedNotification;
use Illuminate\Support\Facades\Notification;

/**
 * Approval engine, Phase B backend (docs/APPROVAL-ENGINE-DESIGN.md): an
 * organisation configures its own chains, a department can name its head,
 * and a chain step can be "the department head".
 */
function workflowSettingsUrl(Tenant $tenant, string $path = ''): string
{
    return "http://{$tenant->subdomain}.ethr.test/api/v1/settings/approval-workflows{$path}";
}

/** @return array<string, mixed> */
function workflowSettingsPayload(array $overrides = []): array
{
    return array_merge([
        'request_type' => 'leave',
        'name' => 'Supervisor then HR',
        'steps' => [
            ['approver_kind' => 'supervisor'],
            ['approver_kind' => 'role', 'approver_ref' => 'hr_admin'],
        ],
    ], $overrides);
}

test('a tenant admin creates, lists, replaces and deletes an approval chain', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    $created = test()->postJson(workflowSettingsUrl($tenant), workflowSettingsPayload())
        ->assertCreated()
        ->assertJsonPath('request_type', 'leave')
        ->assertJsonPath('steps.0.approver_kind', 'supervisor')
        ->assertJsonPath('steps.1.approver_ref', 'hr_admin')
        ->assertJsonPath('steps.1.sla_hours', 48)
        ->assertJsonMissingPath('id');

    $publicId = $created->json('public_id');

    test()->getJson(workflowSettingsUrl($tenant))
        ->assertOk()
        ->assertJsonPath('0.public_id', $publicId)
        ->assertJsonCount(2, '0.steps');

    test()->putJson(workflowSettingsUrl($tenant, "/{$publicId}"), workflowSettingsPayload([
        'name' => 'Department head only',
        'steps' => [['approver_kind' => 'department_head', 'approver_ref' => 'ignored']],
    ]))
        ->assertOk()
        ->assertJsonPath('name', 'Department head only')
        ->assertJsonCount(1, 'steps')
        ->assertJsonPath('steps.0.approver_ref', null);

    test()->deleteJson(workflowSettingsUrl($tenant, "/{$publicId}"))->assertNoContent();

    expect(ApprovalWorkflow::query()->count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'like', 'approval_workflow.%')->pluck('action')->all())
        ->toBe(['approval_workflow.created', 'approval_workflow.updated', 'approval_workflow.deleted']);
});

test('only someone who manages settings can configure approval chains', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::SUPERVISOR], $tenant);

    test()->getJson(workflowSettingsUrl($tenant))->assertForbidden();
    test()->postJson(workflowSettingsUrl($tenant), workflowSettingsPayload())->assertForbidden();
});

test('a chain must name real approvers and stay short', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    test()->postJson(workflowSettingsUrl($tenant), workflowSettingsPayload([
        'steps' => [['approver_kind' => 'role', 'approver_ref' => 'super_admin']],
    ]))->assertUnprocessable()->assertJsonValidationErrors('steps.0.approver_ref');

    test()->postJson(workflowSettingsUrl($tenant), workflowSettingsPayload([
        'steps' => [['approver_kind' => 'user', 'approver_ref' => '01HZNOSUCHUSER00000000000A']],
    ]))->assertUnprocessable()->assertJsonValidationErrors('steps.0.approver_ref');

    test()->postJson(workflowSettingsUrl($tenant), workflowSettingsPayload([
        'steps' => array_fill(0, 6, ['approver_kind' => 'permission']),
    ]))->assertUnprocessable()->assertJsonValidationErrors('steps');

    test()->postJson(workflowSettingsUrl($tenant), workflowSettingsPayload([
        'request_type' => 'correction',
        'conditions' => ['leave_type_ids' => [LeaveType::factory()->create(['tenant_id' => $tenant->id])->public_id]],
    ]))->assertUnprocessable()->assertJsonValidationErrors('conditions.leave_type_ids');
});

test('a chain cannot name another organisation\'s user or department', function () {
    $other = createTenant();
    $foreignUser = createUser(['role' => UserRole::HR_ADMIN], $other);
    $foreignDepartment = Department::factory()->create(['tenant_id' => $other->id]);

    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    test()->postJson(workflowSettingsUrl($tenant), workflowSettingsPayload([
        'steps' => [['approver_kind' => 'user', 'approver_ref' => $foreignUser->public_id]],
    ]))->assertUnprocessable()->assertJsonValidationErrors('steps.0.approver_ref');

    test()->postJson(workflowSettingsUrl($tenant), workflowSettingsPayload([
        'conditions' => ['department_ids' => [$foreignDepartment->public_id]],
    ]))->assertUnprocessable()->assertJsonValidationErrors('conditions.department_ids');
});

test('a department names its head, and a department-head step routes to them', function () {
    $tenant = createTenant();
    $headEmployee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $head = createUser(['role' => UserRole::HR_ADMIN, 'employee_id' => $headEmployee->id], $tenant);
    $department = Department::factory()->create(['tenant_id' => $tenant->id]);

    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);
    test()->putJson("http://{$tenant->subdomain}.ethr.test/api/v1/organization/departments/{$department->public_id}", [
        'head_employee_public_id' => $headEmployee->public_id,
    ])->assertOk()->assertJsonPath('head.public_id', $headEmployee->public_id);

    test()->postJson(workflowSettingsUrl($tenant), workflowSettingsPayload([
        'steps' => [['approver_kind' => 'department_head']],
    ]))->assertCreated();

    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'department_id' => $department->id]);
    $leave = LeaveRequest::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'leave_type_id' => LeaveType::factory()->create(['tenant_id' => $tenant->id])->id,
        'days' => 1,
        'status' => LeaveStatus::PENDING,
    ]);

    expect($leave->approvalInstance()->firstOrFail()->current_approver_user_id)->toBe($head->id);
});

test('a department head asking for leave themselves falls back to HR', function () {
    $tenant = createTenant();
    $headEmployee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    createUser(['role' => UserRole::HR_ADMIN, 'employee_id' => $headEmployee->id], $tenant);
    $department = Department::factory()->create(['tenant_id' => $tenant->id, 'head_employee_id' => $headEmployee->id]);
    $headEmployee->update(['department_id' => $department->id]);

    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);
    test()->postJson(workflowSettingsUrl($tenant), workflowSettingsPayload([
        'steps' => [['approver_kind' => 'department_head']],
    ]))->assertCreated();

    $leave = LeaveRequest::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $headEmployee->id,
        'leave_type_id' => LeaveType::factory()->create(['tenant_id' => $tenant->id])->id,
        'days' => 1,
        'status' => LeaveStatus::PENDING,
    ]);

    $instance = $leave->approvalInstance()->firstOrFail();
    expect($instance->current_approver_user_id)->toBeNull()
        ->and($instance->actions()->pluck('action')->all())->toBe(['fallback']);
});

test('the submission notice goes to the person the first step names', function () {
    Notification::fake();

    $tenant = createTenant();
    $supervisorEmployee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $supervisor = createUser(['role' => UserRole::SUPERVISOR, 'employee_id' => $supervisorEmployee->id], $tenant);
    $named = createUser(['role' => UserRole::HR_ADMIN], $tenant);

    $employee = Employee::factory()->create([
        'tenant_id' => $tenant->id,
        'gender' => 'male',
        'supervisor_id' => $supervisorEmployee->id,
    ]);
    $requester = createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant);

    ApprovalWorkflow::create(['tenant_id' => $tenant->id, 'request_type' => 'leave', 'name' => 'Named'])
        ->steps()->create(['tenant_id' => $tenant->id, 'position' => 1, 'approver_kind' => 'user', 'approver_ref' => $named->public_id]);

    $leaveType = LeaveType::factory()->create(['tenant_id' => $tenant->id, 'code' => 'chain_notice', 'min_notice_days' => 0]);
    LeaveBalance::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
        'year' => now()->year,
        'entitled_days' => 20,
    ]);

    $start = now()->addDay()->startOfDay();
    while ($start->isWeekend()) {
        $start->addDay();
    }

    test()->actingAs($requester)->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/leave/request", [
        'leave_type_public_id' => $leaveType->public_id,
        'start_date' => $start->format('Y-m-d'),
        'end_date' => $start->format('Y-m-d'),
        'reason' => 'Appointment',
    ])->assertCreated();

    Notification::assertSentTo($named, LeaveRequestedNotification::class);
    Notification::assertNotSentTo($supervisor, LeaveRequestedNotification::class);
});
