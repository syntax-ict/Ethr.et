<?php

declare(strict_types=1);

use App\Enums\ApproverKind;
use App\Enums\OvertimeRequestStatus;
use App\Enums\UserRole;
use App\Models\ApprovalWorkflow;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSetting;
use App\Models\Employee;
use App\Models\OvertimeRequest;
use App\Models\PayrollEntry;
use App\Models\Shift;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\OvertimeDecidedNotification;
use App\Notifications\OvertimeRequestedNotification;
use App\Services\Payroll\PayrollEngine;
use App\Services\Payroll\RunRegisterService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Notification;

/**
 * Overtime asked for before it is worked (blueprint S8). Filed like a
 * correction, decided through the approval engine like leave; with the
 * organisation's switch on, payroll flags overtime past what was approved.
 * Pay is never held back.
 */

/** @return array{tenant: Tenant, employee: Employee, requester: User, supervisor: User, hr: User, hr2: User} */
function overtimeOrg(): array
{
    $tenant = createTenant();
    $supervisorEmployee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $supervisor = createUser(['role' => UserRole::SUPERVISOR, 'employee_id' => $supervisorEmployee->id], $tenant);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'supervisor_id' => $supervisorEmployee->id]);
    $requester = createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant);
    $hr = createUser(['role' => UserRole::HR_ADMIN], $tenant);
    $hr2 = createUser(['role' => UserRole::HR_ADMIN], $tenant);

    return compact('tenant', 'employee', 'requester', 'supervisor', 'hr', 'hr2');
}

function overtimeUrl(Tenant $tenant, string $path = ''): string
{
    return "http://{$tenant->subdomain}.ethr.test/api/v1/attendance/overtime-requests{$path}";
}

/** @return array<string, mixed> */
function overtimeBody(array $overrides = []): array
{
    return array_merge([
        'date' => now()->addDays(2)->toDateString(),
        'planned_minutes' => 120,
        'reason' => 'Month-end stock count',
    ], $overrides);
}

test('an employee asks for overtime and their supervisor approves it', function () {
    Notification::fake();
    $org = overtimeOrg();

    $created = $this->actingAs($org['requester'])
        ->postJson(overtimeUrl($org['tenant']), overtimeBody())
        ->assertCreated()
        ->assertJsonPath('status', 'pending')
        ->assertJsonPath('planned_minutes', 120)
        ->assertJsonPath('approval.steps', 1)
        ->assertJsonMissingPath('id');

    Notification::assertSentTo($org['supervisor'], OvertimeRequestedNotification::class);

    app('auth')->forgetGuards();
    $items = $this->actingAs($org['supervisor'])
        ->getJson("http://{$org['tenant']->subdomain}.ethr.test/api/v1/approvals/pending")
        ->assertOk()
        ->json('items');
    expect(collect($items)->firstWhere('public_id', $created->json('public_id'))['type'] ?? null)->toBe('overtime');

    app('auth')->forgetGuards();
    $this->actingAs($org['supervisor'])
        ->putJson(overtimeUrl($org['tenant'], '/'.$created->json('public_id').'/approve'))
        ->assertOk()
        ->assertJsonPath('status', 'approved');

    Notification::assertSentTo($org['requester'], OvertimeDecidedNotification::class);
});

test('an employee cannot ask for a colleague, but HR can, and cannot then approve it', function () {
    $org = overtimeOrg();
    $colleague = Employee::factory()->create(['tenant_id' => $org['tenant']->id]);

    $this->actingAs($org['requester'])
        ->postJson(overtimeUrl($org['tenant']), overtimeBody(['employee_public_id' => $colleague->public_id]))
        ->assertForbidden();

    app('auth')->forgetGuards();
    $created = $this->actingAs($org['hr'])
        ->postJson(overtimeUrl($org['tenant']), overtimeBody(['employee_public_id' => $org['employee']->public_id]))
        ->assertCreated();

    $this->putJson(overtimeUrl($org['tenant'], '/'.$created->json('public_id').'/approve'))
        ->assertForbidden()
        ->assertJsonPath('type', 'https://ethr.et/errors/self-approval');

    app('auth')->forgetGuards();
    $this->actingAs($org['hr2'])
        ->putJson(overtimeUrl($org['tenant'], '/'.$created->json('public_id').'/approve'))
        ->assertOk()
        ->assertJsonPath('status', 'approved');
});

test('nobody approves their own overtime', function () {
    $org = overtimeOrg();
    $hrEmployee = Employee::factory()->create(['tenant_id' => $org['tenant']->id]);
    $hr = createUser(['role' => UserRole::HR_ADMIN, 'employee_id' => $hrEmployee->id], $org['tenant']);

    $created = $this->actingAs($hr)->postJson(overtimeUrl($org['tenant']), overtimeBody())->assertCreated();

    $this->putJson(overtimeUrl($org['tenant'], '/'.$created->json('public_id').'/approve'))
        ->assertForbidden()
        ->assertJsonPath('type', 'https://ethr.et/errors/self-approval');
});

test('a day carries one open request, and a cancelled one frees it', function () {
    $org = overtimeOrg();
    $this->actingAs($org['requester']);

    $first = $this->postJson(overtimeUrl($org['tenant']), overtimeBody())->assertCreated();
    $this->postJson(overtimeUrl($org['tenant']), overtimeBody())
        ->assertUnprocessable()
        ->assertJsonPath('type', 'https://ethr.et/errors/overtime-already-requested');

    $this->putJson(overtimeUrl($org['tenant'], '/'.$first->json('public_id').'/cancel'))
        ->assertOk()
        ->assertJsonPath('status', 'cancelled');

    $this->postJson(overtimeUrl($org['tenant']), overtimeBody())->assertCreated();
});

test('a request stays within the legal four hours and cannot be for a past day', function () {
    $org = overtimeOrg();
    $this->actingAs($org['requester']);

    $this->postJson(overtimeUrl($org['tenant']), overtimeBody(['planned_minutes' => 300]))
        ->assertUnprocessable()->assertJsonValidationErrors('planned_minutes');
    $this->postJson(overtimeUrl($org['tenant']), overtimeBody(['date' => now()->subDay()->toDateString()]))
        ->assertUnprocessable()->assertJsonValidationErrors('date');
});

test('only the employee or whoever asked may cancel, and a rejection needs a reason', function () {
    $org = overtimeOrg();
    $created = $this->actingAs($org['requester'])->postJson(overtimeUrl($org['tenant']), overtimeBody())->assertCreated();
    $id = $created->json('public_id');

    app('auth')->forgetGuards();
    $this->actingAs($org['supervisor'])->putJson(overtimeUrl($org['tenant'], "/{$id}/cancel"))->assertForbidden();
    $this->putJson(overtimeUrl($org['tenant'], "/{$id}/reject"), [])->assertUnprocessable();
    $this->putJson(overtimeUrl($org['tenant'], "/{$id}/reject"), ['reason' => 'Budget is closed this month'])
        ->assertOk()
        ->assertJsonPath('status', 'rejected')
        ->assertJsonPath('decision_note', 'Budget is closed this month');
});

test('an overtime chain decides it in order, and the batch path is the same decision', function () {
    $org = overtimeOrg();
    $workflow = ApprovalWorkflow::create(['tenant_id' => $org['tenant']->id, 'request_type' => 'overtime', 'name' => 'HR first']);
    $workflow->steps()->create(['tenant_id' => $org['tenant']->id, 'position' => 1, 'approver_kind' => ApproverKind::ROLE, 'approver_ref' => 'hr_admin']);

    $created = $this->actingAs($org['requester'])->postJson(overtimeUrl($org['tenant']), overtimeBody())->assertCreated();
    $id = $created->json('public_id');

    app('auth')->forgetGuards();
    $this->actingAs($org['supervisor'])->putJson(overtimeUrl($org['tenant'], "/{$id}/approve"))
        ->assertForbidden()
        ->assertJsonPath('type', 'https://ethr.et/errors/not-current-approver');

    app('auth')->forgetGuards();
    $this->actingAs($org['hr'])
        ->postJson("http://{$org['tenant']->subdomain}.ethr.test/api/v1/approvals/batch", [
            'actions' => [['type' => 'overtime', 'public_id' => $id, 'action' => 'approve']],
        ])
        ->assertOk()
        ->assertJsonPath('results.0.status', 'approved');

    expect(OvertimeRequest::query()->where('public_id', $id)->value('status'))->toBe(OvertimeRequestStatus::APPROVED);
});

test('another organisation\'s employee cannot be named', function () {
    $other = createTenant();
    $stranger = Employee::factory()->create(['tenant_id' => $other->id]);
    $org = overtimeOrg();

    $this->actingAs($org['hr'])
        ->postJson(overtimeUrl($org['tenant']), overtimeBody(['employee_public_id' => $stranger->public_id]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('employee_public_id');
});

/**
 * @param  list<array{0: string, 1: string}>  $days  [date, check-out] worked from 08:30
 */
function overtimePayroll(Tenant $tenant, User $actor, Employee $employee, array $days): PayrollEntry
{
    $employee->update(['salary_cents' => 500000, 'hire_date' => '2020-01-01']);
    $shift = Shift::factory()->create(['tenant_id' => $tenant->id]);

    foreach ($days as [$date, $out]) {
        AttendanceRecord::factory()->create([
            'tenant_id' => $tenant->id,
            'employee_id' => $employee->id,
            'shift_id' => $shift->id,
            'date' => $date,
            'check_in' => Carbon::parse("{$date} 08:30", 'Africa/Addis_Ababa')->utc(),
            'check_out' => Carbon::parse("{$date} {$out}", 'Africa/Addis_Ababa')->utc(),
        ]);
    }

    $run = app(PayrollEngine::class)->process($tenant->id, Carbon::parse('2026-06-01'), Carbon::parse('2026-06-30'), $actor->id)->run;

    return PayrollEntry::where('payroll_run_id', $run->id)->where('employee_id', $employee->id)->firstOrFail();
}

test('with approval required, overtime past what was approved is flagged and still paid', function () {
    $org = overtimeOrg();
    $finance = actingAsUser(['role' => UserRole::FINANCE_ADMIN], $org['tenant']);
    AttendanceSetting::create(['tenant_id' => $org['tenant']->id, ...AttendanceSetting::defaults(), 'overtime_requires_approval' => true]);

    OvertimeRequest::factory()->approved()->create([
        'tenant_id' => $org['tenant']->id, 'employee_id' => $org['employee']->id,
        'date' => '2026-06-01', 'planned_minutes' => 60,
    ]);
    OvertimeRequest::factory()->approved()->create([
        'tenant_id' => $org['tenant']->id, 'employee_id' => $org['employee']->id,
        'date' => '2026-06-03', 'planned_minutes' => 120,
    ]);

    // 1 June: 2 h worked, 1 h approved. 2 June: 1 h, nothing approved. 3 June: 2 h, 2 h approved.
    $entry = overtimePayroll($org['tenant'], $finance, $org['employee'], [
        ['2026-06-01', '19:30'], ['2026-06-02', '18:30'], ['2026-06-03', '19:30'],
    ]);
    $step = collect($entry->calculation_log['steps'])->firstWhere('step', 'overtime');

    expect($step['overtime_minutes'])->toBe(300)
        ->and($step['limits']['requires_approval'])->toBeTrue()
        ->and($step['limits']['days_over_approved'])->toEqual([
            ['date' => '2026-06-01', 'worked_minutes' => 120, 'approved_minutes' => 60],
            ['date' => '2026-06-02', 'worked_minutes' => 60, 'approved_minutes' => 0],
        ]);

    $run = $entry->payrollRun()->firstOrFail();
    $limits = app(RunRegisterService::class)->register($run)['overtime_limits'];
    expect($limits['employees'][0]['days_over_approved'])->toBe(2);
});

test('without approval required, nothing is flagged for want of a request', function () {
    $org = overtimeOrg();
    $finance = actingAsUser(['role' => UserRole::FINANCE_ADMIN], $org['tenant']);

    $entry = overtimePayroll($org['tenant'], $finance, $org['employee'], [['2026-06-02', '18:30']]);
    $step = collect($entry->calculation_log['steps'])->firstWhere('step', 'overtime');

    expect($step['limits']['requires_approval'])->toBeFalse()
        ->and($step['limits']['days_over_approved'])->toEqual([]);
});
