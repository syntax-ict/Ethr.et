<?php

declare(strict_types=1);

use App\Enums\CorrectionStatus;
use App\Enums\LeaveStatus;
use App\Enums\UserRole;
use App\Models\AttendanceCorrection;
use App\Models\AttendanceRecord;
use App\Models\CustomRole;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Permission;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use Carbon\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/**
 * Audit N5: bulk approval was weaker than single approval.
 *
 * `POST /approvals/batch` carried its own copy of the leave and correction
 * decisions. The leave copy had no team check (any `leave.approve` holder
 * approved any leave by id), no self-approval guard, wrote a bare user id into
 * `approved_by` and sent no webhook; the correction copy was authorised by
 * `leave.approve` rather than `correction.approve`, with no team check. Both
 * paths now go through the same policy and the same decision service.
 */
function batchParityPendingLeave(Tenant $tenant, Employee $employee): LeaveRequest
{
    return LeaveRequest::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'leave_type_id' => LeaveType::factory()->create(['tenant_id' => $tenant->id, 'code' => 'bp'.Str::lower(Str::random(8))])->id,
        'status' => LeaveStatus::PENDING,
        'days' => 1,
    ]);
}

function batchParityPendingCorrection(Tenant $tenant, Employee $employee): AttendanceCorrection
{
    $record = AttendanceRecord::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'check_in' => Carbon::today()->setTime(9, 0),
    ]);

    return AttendanceCorrection::factory()->create([
        'tenant_id' => $tenant->id,
        'attendance_record_id' => $record->id,
        'employee_id' => $employee->id,
        'proposed_check_in' => Carbon::today()->setTime(6, 0),
        'status' => CorrectionStatus::PENDING,
    ]);
}

/** @param  list<string>  $permissions */
function batchParityCustomRoleUser(Tenant $tenant, Employee $employee, array $permissions): User
{
    $role = CustomRole::create(['tenant_id' => $tenant->id, 'name' => 'Batch Parity '.implode(',', $permissions), 'org_scope' => 'direct_reports']);
    $role->permissions()->sync(Permission::whereIn('name', $permissions)->pluck('id'));

    return actingAsUser([
        'role' => UserRole::SUPERVISOR,
        'employee_id' => $employee->id,
        'custom_role_id' => $role->id,
    ], $tenant);
}

/** A webhook subscribed to leave decisions; deliveries are recorded synchronously, the HTTP call is faked. */
function batchParityLeaveWebhook(Tenant $tenant): Webhook
{
    Queue::fake();

    return Webhook::factory()->create([
        'tenant_id' => $tenant->id,
        'events' => ['leave.approved', 'leave.rejected'],
    ]);
}

/** @param  array<string, string>  $action */
function batchParityPost(Tenant $tenant, array $action): array
{
    return test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/approvals/batch", [
        'actions' => [$action],
    ])->assertOk()->json('results.0');
}

// ── Leave ──

test('a supervisor cannot batch-approve leave for someone outside their team', function () {
    $tenant = createTenant();
    $supervisor = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $report = Employee::factory()->create(['tenant_id' => $tenant->id, 'supervisor_id' => $supervisor->id]);
    $stranger = Employee::factory()->create(['tenant_id' => $tenant->id]);
    actingAsUser(['role' => UserRole::SUPERVISOR, 'employee_id' => $supervisor->id], $tenant);

    $teamLeave = batchParityPendingLeave($tenant, $report);
    $strangerLeave = batchParityPendingLeave($tenant, $stranger);

    expect(batchParityPost($tenant, ['type' => 'leave', 'public_id' => $teamLeave->public_id, 'action' => 'approve'])['status'])
        ->toBe('approved');

    expect(batchParityPost($tenant, ['type' => 'leave', 'public_id' => $strangerLeave->public_id, 'action' => 'approve'])['status'])
        ->toBe('error');
    expect(batchParityPost($tenant, ['type' => 'leave', 'public_id' => $strangerLeave->public_id, 'action' => 'reject'])['status'])
        ->toBe('error');

    expect($strangerLeave->fresh()->status)->toBe(LeaveStatus::PENDING);
});

test('an approver cannot batch-approve their own leave', function () {
    $tenant = createTenant();
    $hrEmployee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    actingAsUser(['role' => UserRole::HR_ADMIN, 'employee_id' => $hrEmployee->id], $tenant);

    $own = batchParityPendingLeave($tenant, $hrEmployee);

    $result = batchParityPost($tenant, ['type' => 'leave', 'public_id' => $own->public_id, 'action' => 'approve']);

    expect($result['status'])->toBe('error');
    expect($result['detail'])->toBe(__('leave.cannot_approve_own'));
    expect($own->fresh()->status)->toBe(LeaveStatus::PENDING);
});

test('batch leave approval writes the approver entry and sends the webhook the single path does', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    $webhook = batchParityLeaveWebhook($tenant);

    $leave = batchParityPendingLeave($tenant, Employee::factory()->create(['tenant_id' => $tenant->id]));

    expect(batchParityPost($tenant, ['type' => 'leave', 'public_id' => $leave->public_id, 'action' => 'approve'])['status'])
        ->toBe('approved');

    $entry = $leave->fresh()->approved_by[0];
    expect($entry)->toBeArray()
        ->and(array_keys($entry))->toBe(['user_id', 'role', 'at'])
        ->and($entry['user_id'])->toBe($user->id)
        ->and($entry['role'])->toBe('hr_admin');

    $deliveries = WebhookDelivery::where('webhook_id', $webhook->id)->get();
    expect($deliveries->pluck('event')->all())->toBe(['leave.approved']);
    expect($deliveries->first()->payload['data']['public_id'])->toBe($leave->public_id);
});

test('batch leave rejection sends the webhook the single path does', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    $webhook = batchParityLeaveWebhook($tenant);

    $leave = batchParityPendingLeave($tenant, Employee::factory()->create(['tenant_id' => $tenant->id]));

    batchParityPost($tenant, ['type' => 'leave', 'public_id' => $leave->public_id, 'action' => 'reject', 'reason' => 'Staffing']);

    $deliveries = WebhookDelivery::where('webhook_id', $webhook->id)->get();
    expect($deliveries->pluck('event')->all())->toBe(['leave.rejected']);
    expect($deliveries->first()->payload['data']['reason'])->toBe('Staffing');
});

test('another tenant\'s leave id is answered like a nonexistent one', function () {
    $other = createTenant(['subdomain' => 'beta']);
    $foreign = batchParityPendingLeave($other, Employee::factory()->create(['tenant_id' => $other->id]));

    $tenant = createTenant(['subdomain' => 'alpha']);
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    $nonexistent = batchParityPost($tenant, ['type' => 'leave', 'public_id' => '01JZZZZZZZZZZZZZZZZZZZZZZZ', 'action' => 'approve']);
    $crossTenant = batchParityPost($tenant, ['type' => 'leave', 'public_id' => $foreign->public_id, 'action' => 'approve']);

    expect(array_diff_key($crossTenant, ['public_id' => 1]))->toBe(array_diff_key($nonexistent, ['public_id' => 1]));
    expect(LeaveRequest::withoutGlobalScopes()->find($foreign->id)->status)->toBe(LeaveStatus::PENDING);
});

// ── Corrections ──

test('leave.approve without correction.approve cannot batch-decide a correction', function () {
    $tenant = createTenant();
    $supervisor = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $report = Employee::factory()->create(['tenant_id' => $tenant->id, 'supervisor_id' => $supervisor->id]);
    batchParityCustomRoleUser($tenant, $supervisor, ['leave.approve', 'leave.viewTeam']);

    $correction = batchParityPendingCorrection($tenant, $report);

    expect(batchParityPost($tenant, ['type' => 'correction', 'public_id' => $correction->public_id, 'action' => 'approve'])['status'])
        ->toBe('error');

    expect($correction->fresh()->status)->toBe(CorrectionStatus::PENDING);
    expect($correction->attendanceRecord->fresh()->check_in->format('H:i'))->toBe('09:00');
});

test('correction.approve without leave.approve can batch-decide a correction for a report', function () {
    $tenant = createTenant();
    $supervisor = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $report = Employee::factory()->create(['tenant_id' => $tenant->id, 'supervisor_id' => $supervisor->id]);
    batchParityCustomRoleUser($tenant, $supervisor, ['correction.approve', 'correction.viewPending']);

    $correction = batchParityPendingCorrection($tenant, $report);

    expect(batchParityPost($tenant, ['type' => 'correction', 'public_id' => $correction->public_id, 'action' => 'approve'])['status'])
        ->toBe('approved');
    expect($correction->fresh()->status)->toBe(CorrectionStatus::APPROVED);
});

test('a role holding none of the decision permissions is refused the batch endpoint', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    actingAsUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant);

    test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/approvals/batch", [
        'actions' => [['type' => 'leave', 'public_id' => '01JZZZZZZZZZZZZZZZZZZZZZZZ', 'action' => 'approve']],
    ])->assertForbidden();
});

test('a supervisor cannot batch-decide a correction for someone outside their team', function () {
    $tenant = createTenant();
    $supervisor = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $stranger = Employee::factory()->create(['tenant_id' => $tenant->id]);
    actingAsUser(['role' => UserRole::SUPERVISOR, 'employee_id' => $supervisor->id], $tenant);

    $correction = batchParityPendingCorrection($tenant, $stranger);

    expect(batchParityPost($tenant, ['type' => 'correction', 'public_id' => $correction->public_id, 'action' => 'approve'])['status'])
        ->toBe('error');
    expect($correction->fresh()->status)->toBe(CorrectionStatus::PENDING);
});

test('an approver cannot batch-approve a correction to their own attendance', function () {
    $tenant = createTenant();
    $hrEmployee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    actingAsUser(['role' => UserRole::HR_ADMIN, 'employee_id' => $hrEmployee->id], $tenant);

    $own = batchParityPendingCorrection($tenant, $hrEmployee);

    expect(batchParityPost($tenant, ['type' => 'correction', 'public_id' => $own->public_id, 'action' => 'approve'])['status'])
        ->toBe('error');
    expect($own->fresh()->status)->toBe(CorrectionStatus::PENDING);
});

// ── The approvals summary ──

test('the approvals summary lists a pending correction instead of failing', function () {
    // `ApprovalController::pending` read `$c->date`, a column corrections do
    // not have (PHPStan baselined it as property.notFound), so a single pending
    // correction in the team made the endpoint a 500.
    $tenant = createTenant();
    $supervisor = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $report = Employee::factory()->create(['tenant_id' => $tenant->id, 'supervisor_id' => $supervisor->id]);
    actingAsUser(['role' => UserRole::SUPERVISOR, 'employee_id' => $supervisor->id], $tenant);

    $correction = batchParityPendingCorrection($tenant, $report);
    $correction->attendanceRecord->update(['date' => '2026-09-29']);

    $response = test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/approvals/pending")
        ->assertOk();

    expect($response->json('items.0.type'))->toBe('correction');
    expect($response->json('items.0.summary'))->toBe('Attendance correction for Sep 29');
});
