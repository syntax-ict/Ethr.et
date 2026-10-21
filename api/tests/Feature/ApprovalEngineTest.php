<?php

declare(strict_types=1);

use App\Enums\ApproverKind;
use App\Enums\CorrectionStatus;
use App\Enums\LeaveStatus;
use App\Enums\UserRole;
use App\Models\ApprovalAction;
use App\Models\ApprovalInstance;
use App\Models\ApprovalWorkflow;
use App\Models\AttendanceCorrection;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Tenant;
use App\Models\User;
use Carbon\Carbon;

/**
 * The approval engine, Phase A (docs/APPROVAL-ENGINE-DESIGN.md).
 *
 * With nothing configured every request is decided as before, in one step.
 * With a chain, a request is decided by exactly the configured approvers, in
 * order; nobody decides their own request or two steps of the same one; and a
 * workflow edited mid-flight changes nothing already submitted.
 */

/**
 * @return array{tenant: Tenant, employee: Employee, supervisor: User, hr: User, hr2: User, requester: User, leaveType: LeaveType, balance: LeaveBalance}
 */
function approvalEngineOrg(bool $withSupervisor = true): array
{
    $tenant = createTenant();

    $supervisorEmployee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $supervisor = createUser(['role' => UserRole::SUPERVISOR, 'employee_id' => $supervisorEmployee->id], $tenant);

    $employee = Employee::factory()->create([
        'tenant_id' => $tenant->id,
        'supervisor_id' => $withSupervisor ? $supervisorEmployee->id : null,
    ]);
    $requester = createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant);

    $hr = createUser(['role' => UserRole::HR_ADMIN], $tenant);
    $hr2 = createUser(['role' => UserRole::HR_ADMIN], $tenant);

    $leaveType = LeaveType::factory()->create(['tenant_id' => $tenant->id, 'code' => 'engine_annual']);
    $balance = LeaveBalance::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
        'year' => now()->year,
        'entitled_days' => 20,
        'pending_days' => 3,
        'used_days' => 0,
    ]);

    return compact('tenant', 'employee', 'supervisor', 'hr', 'hr2', 'requester', 'leaveType', 'balance');
}

/**
 * @param  list<array{0: ApproverKind, 1?: string|null}>  $steps
 * @param  array<string, list<string>>|null  $conditions
 */
function approvalEngineWorkflow(Tenant $tenant, string $type, array $steps, ?array $conditions = null): ApprovalWorkflow
{
    $workflow = ApprovalWorkflow::create([
        'tenant_id' => $tenant->id,
        'request_type' => $type,
        'name' => 'Chain',
        'conditions' => $conditions,
    ]);

    foreach ($steps as $i => $step) {
        $workflow->steps()->create([
            'tenant_id' => $tenant->id,
            'position' => $i + 1,
            'approver_kind' => $step[0],
            'approver_ref' => $step[1] ?? null,
        ]);
    }

    return $workflow;
}

/** @param  array{tenant: Tenant, employee: Employee, leaveType: LeaveType}  $org */
function approvalEngineLeave(array $org): LeaveRequest
{
    return LeaveRequest::factory()->create([
        'tenant_id' => $org['tenant']->id,
        'employee_id' => $org['employee']->id,
        'leave_type_id' => $org['leaveType']->id,
        'start_date' => now()->addDays(10)->toDateString(),
        'end_date' => now()->addDays(12)->toDateString(),
        'days' => 3,
        'status' => LeaveStatus::PENDING,
    ]);
}

function approvalEngineUrl(Tenant $tenant, string $path): string
{
    return "http://{$tenant->subdomain}.ethr.test/api/v1/{$path}";
}

/** @return list<string> */
function approvalEnginePending(Tenant $tenant, User $user): array
{
    test()->actingAs($user);
    app('auth')->forgetGuards();

    return collect(test()->actingAs($user)->getJson(approvalEngineUrl($tenant, 'approvals/pending'))->assertOk()->json('items'))
        ->pluck('public_id')
        ->all();
}

test('with no workflow configured, one approval decides a request exactly as before', function () {
    $org = approvalEngineOrg();
    $leave = approvalEngineLeave($org);

    test()->actingAs($org['hr'])
        ->putJson(approvalEngineUrl($org['tenant'], "leave/{$leave->public_id}/approve"))
        ->assertOk()
        ->assertJsonPath('status', 'approved')
        ->assertJsonPath('approval.step', 1)
        ->assertJsonPath('approval.steps', 1)
        ->assertJsonPath('approval.history.0.action', 'approve');

    expect((float) $org['balance']->refresh()->used_days)->toBe(3.0)
        ->and($leave->approvalInstance()->first()?->status)->toBe(ApprovalInstance::STATUS_APPROVED);
});

test('a request submitted before the engine shipped is decided on the default single step', function () {
    $org = approvalEngineOrg();
    approvalEngineWorkflow($org['tenant'], 'leave', [[ApproverKind::SUPERVISOR], [ApproverKind::ROLE, 'hr_admin']]);
    $leave = approvalEngineLeave($org);

    // As if submitted before the engine: no instance at all.
    ApprovalInstance::query()->delete();

    expect(approvalEnginePending($org['tenant'], $org['hr']))->toContain($leave->public_id);

    test()->actingAs($org['hr'])
        ->putJson(approvalEngineUrl($org['tenant'], "leave/{$leave->public_id}/approve"))
        ->assertOk()
        ->assertJsonPath('status', 'approved')
        ->assertJsonPath('approval.history.0.action', 'approve');
});

test('a two-step chain keeps the request pending until its last step', function () {
    $org = approvalEngineOrg();
    approvalEngineWorkflow($org['tenant'], 'leave', [[ApproverKind::SUPERVISOR], [ApproverKind::ROLE, 'hr_admin']]);
    $leave = approvalEngineLeave($org);

    test()->actingAs($org['supervisor'])
        ->putJson(approvalEngineUrl($org['tenant'], "leave/{$leave->public_id}/approve"))
        ->assertOk()
        ->assertJsonPath('status', 'pending')
        ->assertJsonPath('approval.step', 2)
        ->assertJsonPath('approval.steps', 2);

    $balance = $org['balance']->refresh();
    expect((float) $balance->used_days)->toBe(0.0)
        ->and((float) $balance->pending_days)->toBe(3.0);

    app('auth')->forgetGuards();
    test()->actingAs($org['hr'])
        ->putJson(approvalEngineUrl($org['tenant'], "leave/{$leave->public_id}/approve"))
        ->assertOk()
        ->assertJsonPath('status', 'approved');

    $balance->refresh();
    expect((float) $balance->used_days)->toBe(3.0)
        ->and((float) $balance->pending_days)->toBe(0.0);
});

test('a later step\'s approver can neither see nor decide an earlier step', function () {
    $org = approvalEngineOrg();
    approvalEngineWorkflow($org['tenant'], 'leave', [[ApproverKind::SUPERVISOR], [ApproverKind::ROLE, 'hr_admin']]);
    $leave = approvalEngineLeave($org);

    expect(approvalEnginePending($org['tenant'], $org['hr']))->not->toContain($leave->public_id);

    test()->actingAs($org['hr'])
        ->putJson(approvalEngineUrl($org['tenant'], "leave/{$leave->public_id}/approve"))
        ->assertForbidden()
        ->assertJsonPath('type', 'https://ethr.et/errors/not-current-approver');

    test()->actingAs($org['hr'])
        ->postJson(approvalEngineUrl($org['tenant'], 'approvals/batch'), [
            'actions' => [['type' => 'leave', 'public_id' => $leave->public_id, 'action' => 'approve']],
        ])
        ->assertOk()
        ->assertJsonPath('results.0.status', 'error');

    app('auth')->forgetGuards();
    $items = test()->actingAs($org['supervisor'])->getJson(approvalEngineUrl($org['tenant'], 'approvals/pending'))->assertOk()->json('items');
    expect($items)->toHaveCount(1)
        ->and($items[0]['step'])->toBe(1)
        ->and($items[0]['steps'])->toBe(2);

    expect($leave->refresh()->status)->toBe(LeaveStatus::PENDING);
});

test('one person cannot approve two steps of the same request', function () {
    $org = approvalEngineOrg();
    approvalEngineWorkflow($org['tenant'], 'leave', [[ApproverKind::PERMISSION], [ApproverKind::PERMISSION]]);
    $leave = approvalEngineLeave($org);

    test()->actingAs($org['hr'])
        ->putJson(approvalEngineUrl($org['tenant'], "leave/{$leave->public_id}/approve"))
        ->assertOk()
        ->assertJsonPath('status', 'pending');

    app('auth')->forgetGuards();
    test()->actingAs($org['hr'])
        ->putJson(approvalEngineUrl($org['tenant'], "leave/{$leave->public_id}/approve"))
        ->assertForbidden()
        ->assertJsonPath('type', 'https://ethr.et/errors/not-current-approver');

    expect(approvalEnginePending($org['tenant'], $org['hr']))->not->toContain($leave->public_id)
        ->and(approvalEnginePending($org['tenant'], $org['hr2']))->toContain($leave->public_id);

    app('auth')->forgetGuards();
    test()->actingAs($org['hr2'])
        ->putJson(approvalEngineUrl($org['tenant'], "leave/{$leave->public_id}/approve"))
        ->assertOk()
        ->assertJsonPath('status', 'approved');
});

test('a supervisor step with no supervisor falls back to the permission holders and says so', function () {
    $org = approvalEngineOrg(withSupervisor: false);
    approvalEngineWorkflow($org['tenant'], 'leave', [[ApproverKind::SUPERVISOR]]);
    $leave = approvalEngineLeave($org);

    $instance = $leave->approvalInstance()->firstOrFail();
    expect($instance->current_approver_user_id)->toBeNull()
        ->and($instance->current_approver_role)->toBeNull()
        ->and($instance->actions()->pluck('action')->all())->toBe(['fallback']);

    test()->actingAs($org['hr'])
        ->putJson(approvalEngineUrl($org['tenant'], "leave/{$leave->public_id}/approve"))
        ->assertOk()
        ->assertJsonPath('status', 'approved')
        ->assertJsonPath('approval.history.0.action', 'fallback')
        ->assertJsonPath('approval.history.1.action', 'approve');
});

test('a step that names the requester falls back instead of letting them decide', function () {
    $org = approvalEngineOrg();
    $hrEmployee = Employee::factory()->create(['tenant_id' => $org['tenant']->id]);
    $hrRequester = createUser(['role' => UserRole::HR_ADMIN, 'employee_id' => $hrEmployee->id], $org['tenant']);
    approvalEngineWorkflow($org['tenant'], 'leave', [[ApproverKind::USER, $hrRequester->public_id]]);

    $leave = LeaveRequest::factory()->create([
        'tenant_id' => $org['tenant']->id,
        'employee_id' => $hrEmployee->id,
        'leave_type_id' => $org['leaveType']->id,
        'days' => 1,
        'status' => LeaveStatus::PENDING,
    ]);

    $instance = $leave->approvalInstance()->firstOrFail();
    expect($instance->current_approver_user_id)->toBeNull()
        ->and($instance->actions()->pluck('action')->all())->toBe(['fallback']);

    test()->actingAs($hrRequester)
        ->putJson(approvalEngineUrl($org['tenant'], "leave/{$leave->public_id}/approve"))
        ->assertForbidden();

    app('auth')->forgetGuards();
    test()->actingAs($org['hr'])
        ->putJson(approvalEngineUrl($org['tenant'], "leave/{$leave->public_id}/approve"))
        ->assertOk()
        ->assertJsonPath('status', 'approved');
});

test('the person who submitted a request is never resolved as a step approver', function () {
    $org = approvalEngineOrg();
    approvalEngineWorkflow($org['tenant'], 'leave', [[ApproverKind::USER, $org['hr']->public_id]]);

    // HR files on the employee's behalf, then the step names HR.
    test()->actingAs($org['hr']);
    $leave = approvalEngineLeave($org);

    $instance = $leave->approvalInstance()->firstOrFail();
    expect($instance->submitted_by)->toBe($org['hr']->id)
        ->and($instance->current_approver_user_id)->toBeNull()
        ->and($instance->actions()->pluck('action')->all())->toBe(['fallback']);
});

test('editing a workflow does not change a request already in flight', function () {
    $org = approvalEngineOrg();
    $workflow = approvalEngineWorkflow($org['tenant'], 'leave', [[ApproverKind::SUPERVISOR], [ApproverKind::ROLE, 'hr_admin']]);
    $leave = approvalEngineLeave($org);

    $workflow->steps()->where('position', 2)->delete();
    $workflow->update(['is_active' => false]);

    test()->actingAs($org['supervisor'])
        ->putJson(approvalEngineUrl($org['tenant'], "leave/{$leave->public_id}/approve"))
        ->assertOk()
        ->assertJsonPath('status', 'pending')
        ->assertJsonPath('approval.step', 2);

    // A request submitted now gets the default single step.
    $later = approvalEngineLeave($org);
    expect($later->approvalInstance()->firstOrFail()->stepCount())->toBe(1);
});

test('a rejection at the first step ends the request and releases the pending days', function () {
    $org = approvalEngineOrg();
    approvalEngineWorkflow($org['tenant'], 'leave', [[ApproverKind::SUPERVISOR], [ApproverKind::ROLE, 'hr_admin']]);
    $leave = approvalEngineLeave($org);

    test()->actingAs($org['supervisor'])
        ->putJson(approvalEngineUrl($org['tenant'], "leave/{$leave->public_id}/reject"), ['reason' => 'Short staffed that week'])
        ->assertOk()
        ->assertJsonPath('status', 'rejected')
        ->assertJsonPath('approval.status', 'rejected')
        ->assertJsonPath('approval.history.0.comment', 'Short staffed that week');

    expect((float) $org['balance']->refresh()->pending_days)->toBe(0.0)
        ->and(approvalEnginePending($org['tenant'], $org['hr']))->not->toContain($leave->public_id);
});

test('a workflow applies only to the leave types it names', function () {
    $org = approvalEngineOrg();
    $other = LeaveType::factory()->create(['tenant_id' => $org['tenant']->id, 'code' => 'engine_other']);
    approvalEngineWorkflow(
        $org['tenant'],
        'leave',
        [[ApproverKind::SUPERVISOR], [ApproverKind::ROLE, 'hr_admin']],
        ['leave_type_ids' => [$other->public_id]],
    );

    $leave = approvalEngineLeave($org);

    expect($leave->approvalInstance()->firstOrFail()->stepCount())->toBe(1);
});

test('the highest-priority matching workflow wins', function () {
    $org = approvalEngineOrg();
    approvalEngineWorkflow($org['tenant'], 'leave', [[ApproverKind::PERMISSION]]);
    approvalEngineWorkflow($org['tenant'], 'leave', [[ApproverKind::SUPERVISOR], [ApproverKind::ROLE, 'hr_admin']])
        ->update(['priority' => 10]);

    expect(approvalEngineLeave($org)->approvalInstance()->firstOrFail()->stepCount())->toBe(2);
});

test('attendance corrections run through the same engine', function () {
    $org = approvalEngineOrg();
    approvalEngineWorkflow($org['tenant'], 'correction', [[ApproverKind::SUPERVISOR], [ApproverKind::ROLE, 'hr_admin']]);

    $record = AttendanceRecord::factory()->create([
        'tenant_id' => $org['tenant']->id,
        'employee_id' => $org['employee']->id,
        'date' => Carbon::today()->subDay()->toDateString(),
        'check_in' => Carbon::today()->subDay()->setTime(9, 30),
        'check_out' => Carbon::today()->subDay()->setTime(17, 0),
    ]);
    $correction = AttendanceCorrection::factory()->create([
        'tenant_id' => $org['tenant']->id,
        'attendance_record_id' => $record->id,
        'employee_id' => $org['employee']->id,
        'proposed_check_in' => Carbon::today()->subDay()->setTime(8, 0),
        'status' => CorrectionStatus::PENDING,
    ]);

    test()->actingAs($org['hr'])
        ->putJson(approvalEngineUrl($org['tenant'], "attendance/corrections/{$correction->public_id}/approve"))
        ->assertForbidden()
        ->assertJsonPath('type', 'https://ethr.et/errors/not-current-approver');

    app('auth')->forgetGuards();
    test()->actingAs($org['supervisor'])
        ->putJson(approvalEngineUrl($org['tenant'], "attendance/corrections/{$correction->public_id}/approve"))
        ->assertOk()
        ->assertJsonPath('status', 'pending')
        ->assertJsonPath('approval.step', 2);

    expect($record->refresh()->check_in?->format('H:i'))->toBe('09:30');

    app('auth')->forgetGuards();
    test()->actingAs($org['hr'])
        ->putJson(approvalEngineUrl($org['tenant'], "attendance/corrections/{$correction->public_id}/approve"))
        ->assertOk()
        ->assertJsonPath('status', 'approved');

    expect($record->refresh()->check_in?->format('H:i'))->toBe('08:00');
});

test('cancelling a request closes its approval', function () {
    $org = approvalEngineOrg();
    $leave = approvalEngineLeave($org);

    test()->actingAs($org['requester'])
        ->putJson(approvalEngineUrl($org['tenant'], "leave/{$leave->public_id}/cancel"))
        ->assertOk();

    expect($leave->approvalInstance()->firstOrFail()->status)->toBe(ApprovalInstance::STATUS_CANCELLED);
});

test('approval actions cannot be rewritten or removed', function () {
    $org = approvalEngineOrg();
    approvalEngineWorkflow($org['tenant'], 'leave', [[ApproverKind::SUPERVISOR], [ApproverKind::ROLE, 'hr_admin']]);
    $leave = approvalEngineLeave($org);

    test()->actingAs($org['supervisor'])
        ->putJson(approvalEngineUrl($org['tenant'], "leave/{$leave->public_id}/approve"))
        ->assertOk();

    $action = ApprovalAction::query()->firstOrFail();

    expect(fn () => $action->update(['action' => 'reject']))->toThrow(LogicException::class)
        ->and(fn () => $action->delete())->toThrow(LogicException::class);
});
