<?php

declare(strict_types=1);

use App\Enums\EmployeeStatus;
use App\Enums\UserRole;
use App\Exceptions\PayrollRunRefused;
use App\Jobs\ComputePayDifferencesJob;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\EmployeeCostSharing;
use App\Models\EmployeeLoan;
use App\Models\PayrollEntry;
use App\Models\PayrollPayDifference;
use App\Models\PayrollRule;
use App\Models\PayrollRun;
use App\Models\Tenant;
use App\Models\User;
use App\Services\CurrentTenant;
use App\Services\Payroll\PayDifferenceRecorder;
use App\Services\Payroll\PayrollEngine;
use App\Services\Payroll\SalaryHistoryWriter;
use App\Services\PersonnelActionService;
use Carbon\Carbon;
use Illuminate\Support\Carbon as IlluminateCarbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/**
 * A change to pay that reaches back into a month already released records the
 * difference for finance, and the next approval must acknowledge it
 * (docs/audit/BASELINE.md §25x).
 */
afterEach(function () {
    IlluminateCarbon::setTestNow();
});

/** @return array{0: Tenant, 1: User, 2: Employee, 3: PayrollRun} October paid and approved, the queue faked. */
function pdApprovedOctober(array $employee = []): array
{
    test()->travelTo(IlluminateCarbon::parse('2026-11-05 09:00:00', 'UTC'));
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);
    $person = Employee::factory()->create([
        'tenant_id' => $tenant->id,
        'salary_cents' => 1_000_000,
        'hire_date' => '2024-01-01',
        ...$employee,
    ]);
    Queue::fake();

    $run = pdRun($tenant->id, $user->id, '2026-10-01', '2026-10-31');
    app(PayrollEngine::class)->approve($run, $user->id);

    return [$tenant, $user, $person, $run->fresh()];
}

function pdRun(int $tenantId, int $userId, string $start, string $end): PayrollRun
{
    return app(PayrollEngine::class)->process($tenantId, Carbon::parse($start), Carbon::parse($end), $userId)->run;
}

function pdRaise(Employee $employee, int $userId, string $effective, int $cents): void
{
    app(PersonnelActionService::class)->record($employee, [
        'type' => 'promotion',
        'effective_date' => $effective,
        'new_salary_cents' => $cents,
    ], $userId);
}

function pdCompute(int $tenantId): int
{
    return app(PayDifferenceRecorder::class)->computePending($tenantId);
}

function pdLive(int $employeeId): ?PayrollPayDifference
{
    return PayrollPayDifference::query()->where('employee_id', $employeeId)->whereIn('status', ['pending', 'open'])->first();
}

it('records an underpaid difference for a raise back-dated into an approved month, and the next approval must acknowledge it', function () {
    [$tenant, $user, $employee, $october] = pdApprovedOctober();

    pdRaise($employee, $user->id, '2026-10-10', 1_500_000);

    Queue::assertPushed(ComputePayDifferencesJob::class, fn ($job) => $job->tenantId === $tenant->id && $job->employeeId === $employee->id);
    expect(pdLive($employee->id)->status)->toBe('pending');

    (new ComputePayDifferencesJob($tenant->id, $employee->id))->handle(app(PayDifferenceRecorder::class), app(CurrentTenant::class));

    $row = pdLive($employee->id);
    expect($row->status)->toBe('open')
        ->and($row->kind)->toBe('underpaid')
        ->and($row->gross_cents)->toBe(500_000)
        ->and($row->net_cents)->toBeGreaterThan(0)
        ->and($row->payroll_run_id)->toBe($october->id)
        ->and($row->changed_from->toDateString())->toBe('2026-10-10')
        ->and($row->detail['trail_rebuilt_at_release'])->toBeFalse();

    $november = pdRun($tenant->id, $user->id, '2026-11-01', '2026-11-30');

    test()->putJson("/api/v1/payroll/runs/{$november->public_id}/approve")
        ->assertStatus(422)
        ->assertJsonPath('type', 'https://ethr.et/errors/pay-differences-unacknowledged')
        ->assertJsonPath('pay_differences', 1);

    test()->putJson("/api/v1/payroll/runs/{$november->public_id}/approve", ['acknowledged_pay_differences' => 2])
        ->assertStatus(422);

    test()->putJson("/api/v1/payroll/runs/{$november->public_id}/approve", ['acknowledged_pay_differences' => 1])
        ->assertOk()
        ->assertJsonPath('status', 'approved');

    expect(pdLive($employee->id)->status)->toBe('open')
        ->and(AuditLog::query()->where('action', 'payroll.approved')->latest('id')->first()->payload['acknowledged_pay_differences'])->toBe(1);
});

it('records the whole entry as overpaid when a leaving date is set before an approved period', function () {
    [$tenant, , $employee, $october] = pdApprovedOctober();
    $paid = PayrollEntry::query()->where('payroll_run_id', $october->id)->sole();

    $employee->update(['status' => EmployeeStatus::TERMINATED, 'termination_date' => '2026-09-15']);
    pdCompute($tenant->id);

    $row = pdLive($employee->id);
    expect($row->status)->toBe('open')
        ->and($row->kind)->toBe('overpaid')
        ->and($row->due_gross_cents)->toBe(0)
        ->and($row->due_net_cents)->toBe(0)
        ->and($row->gross_cents)->toBe(-$paid->gross_cents)
        ->and($row->net_cents)->toBe(-$paid->net_cents)
        ->and($row->changed_from->toDateString())->toBe('2026-09-15');
});

it('records the proration as overpaid when a leaving date is set inside an approved period', function () {
    [$tenant, , $employee] = pdApprovedOctober();

    $employee->update(['status' => EmployeeStatus::RESIGNED, 'termination_date' => '2026-10-16']);
    pdCompute($tenant->id);

    $kept = (int) round(1_000_000 * 16 / 31);
    $row = pdLive($employee->id);
    expect($row->kind)->toBe('overpaid')
        ->and($row->gross_cents)->toBe($kept - 1_000_000)
        ->and($row->due_gross_cents)->toBe($kept);
});

it('does not show a loan paid off since the month was paid as a difference', function () {
    $this->travelTo(IlluminateCarbon::parse('2026-11-05 09:00:00', 'UTC'));
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'salary_cents' => 1_000_000, 'hire_date' => '2024-01-01']);
    $loan = EmployeeLoan::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'amount_cents' => 100_000,
        'remaining_cents' => 100_000,
        'monthly_deduction_cents' => 50_000,
        'start_date' => '2026-01-01',
    ]);
    Queue::fake();
    $run = pdRun($tenant->id, $user->id, '2026-10-01', '2026-10-31');
    app(PayrollEngine::class)->approve($run, $user->id);
    expect(PayrollEntry::query()->where('payroll_run_id', $run->id)->sole()->other_deductions_cents)->toBeGreaterThan(0);

    $loan->update(['remaining_cents' => 0, 'status' => 'completed']);

    app(SalaryHistoryWriter::class)->record($employee, 1_000_000, '2026-10-10', 'edit', null, $user->id);
    expect(pdLive($employee->id)->status)->toBe('pending');
    pdCompute($tenant->id);

    expect(pdLive($employee->id))->toBeNull()
        ->and(PayrollPayDifference::query()->count())->toBe(0);
});

it('leaves no live row when the change is put back', function () {
    [$tenant, $user, $employee] = pdApprovedOctober();

    pdRaise($employee, $user->id, '2026-10-10', 1_500_000);
    pdRaise($employee->fresh(), $user->id, '2026-10-10', 1_000_000);
    pdCompute($tenant->id);

    expect(pdLive($employee->id))->toBeNull()
        ->and(PayrollPayDifference::query()->count())->toBe(0);
});

it('measures a further change from what a settled difference already covers', function () {
    [$tenant, $user, $employee] = pdApprovedOctober();

    pdRaise($employee, $user->id, '2026-10-10', 1_500_000);
    pdCompute($tenant->id);
    $first = pdLive($employee->id);

    test()->postJson("/api/v1/payroll/pay-differences/{$first->public_id}/settle", ['note' => 'paid with the December run'])
        ->assertOk()
        ->assertJsonPath('status', 'settled');
    expect(pdLive($employee->id))->toBeNull();

    pdRaise($employee->fresh(), $user->id, '2026-10-12', 1_700_000);
    pdCompute($tenant->id);

    $second = pdLive($employee->id);
    expect($second->gross_cents)->toBe(200_000)
        ->and($second->detail['handled_gross_cents'])->toBe(500_000)
        ->and($second->detail['handled_rows'])->toBe(1)
        ->and($first->fresh()->status)->toBe('settled');
});

it('supersedes the live rows of a run that is voided and keeps the handled ones', function () {
    [$tenant, $user, $employee, $october] = pdApprovedOctober();
    $other = Employee::factory()->create(['tenant_id' => $tenant->id, 'salary_cents' => 900_000, 'hire_date' => '2024-01-01']);
    $second = pdRun($tenant->id, $user->id, '2026-09-01', '2026-09-30');
    app(PayrollEngine::class)->approve($second, $user->id);

    pdRaise($employee, $user->id, '2026-09-20', 1_500_000);
    pdRaise($other, $user->id, '2026-09-20', 1_100_000);
    pdCompute($tenant->id);
    $otherRow = PayrollPayDifference::query()->where('employee_id', $other->id)->sole();
    expect($otherRow->status)->toBe('open');
    $rows = PayrollPayDifference::query()->where('employee_id', $employee->id)->get();
    expect($rows)->toHaveCount(2);
    $settled = $rows->firstWhere('payroll_run_id', $second->id);
    $settled->update(['status' => 'settled']);

    app(PayrollEngine::class)->void($october->fresh(), $user->id, 'redo');

    expect(PayrollPayDifference::query()->where('payroll_run_id', $october->id)->sole()->status)->toBe('superseded')
        ->and($settled->fresh()->status)->toBe('settled')
        ->and($otherRow->fresh()->status)->toBe('open')
        ->and($otherRow->fresh()->payroll_run_id)->toBe($second->id);
});

it('works out pending rows inline at approval, without the job, and refuses when more than twenty wait', function () {
    [$tenant, $user, $employee, $october] = pdApprovedOctober();
    pdRaise($employee, $user->id, '2026-10-10', 1_500_000);
    expect(pdLive($employee->id)->status)->toBe('pending');

    $november = pdRun($tenant->id, $user->id, '2026-11-01', '2026-11-30');
    test()->putJson("/api/v1/payroll/runs/{$november->public_id}/approve")
        ->assertStatus(422)
        ->assertJsonPath('type', 'https://ethr.et/errors/pay-differences-unacknowledged');
    expect(pdLive($employee->id)->status)->toBe('open');

    DB::table('payroll_pay_differences')->delete();
    $entryOf = PayrollEntry::query()->where('payroll_run_id', $october->id)->sole();
    $people = Employee::factory()->count(51)->create(['tenant_id' => $tenant->id, 'salary_cents' => 1_000_000, 'hire_date' => '2024-01-01']);
    foreach ($people as $person) {
        DB::table('payroll_pay_differences')->insert([
            'public_id' => (string) Str::ulid(), 'tenant_id' => $tenant->id, 'employee_id' => $person->id,
            'payroll_run_id' => $october->id, 'payroll_entry_id' => $entryOf->id, 'status' => 'pending',
            'changed_from' => '2026-10-10', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
    $big = pdRun($tenant->id, $user->id, '2026-12-01', '2026-12-31');

    test()->putJson("/api/v1/payroll/runs/{$big->public_id}/approve")
        ->assertStatus(422)
        ->assertJsonPath('type', 'https://ethr.et/errors/pay-differences-pending');

    expect(PayrollPayDifference::query()->where('status', 'pending')->count())->toBe(31)
        ->and(PayrollPayDifference::query()->where('status', 'open')->count())->toBe(20);
});

it('records nothing for a change dated after every released period', function () {
    [$tenant, $user, $employee] = pdApprovedOctober();

    pdRaise($employee, $user->id, '2026-11-01', 1_500_000);

    expect(PayrollPayDifference::query()->count())->toBe(0);
    Queue::assertNotPushed(ComputePayDifferencesJob::class);
});

it('settles and dismisses only an open difference, with a note, and writes an audit row', function () {
    [$tenant, $user, $employee] = pdApprovedOctober();
    pdRaise($employee, $user->id, '2026-10-10', 1_500_000);
    pdCompute($tenant->id);
    $row = pdLive($employee->id);

    test()->postJson("/api/v1/payroll/pay-differences/{$row->public_id}/dismiss", [])->assertStatus(422);
    test()->postJson("/api/v1/payroll/pay-differences/{$row->public_id}/settle", ['note' => ''])->assertStatus(422);

    test()->postJson("/api/v1/payroll/pay-differences/{$row->public_id}/dismiss", ['note' => 'agreed with the employee'])
        ->assertOk()
        ->assertJsonPath('status', 'dismissed')
        ->assertJsonPath('note', 'agreed with the employee');
    expect(AuditLog::query()->where('action', 'payroll.pay_difference_dismissed')->count())->toBe(1)
        ->and($row->fresh()->settled_by)->toBe($user->id);

    test()->postJson("/api/v1/payroll/pay-differences/{$row->public_id}/settle", ['note' => 'again'])
        ->assertStatus(422)
        ->assertJsonPath('type', 'https://ethr.et/errors/invalid-state');

    pdRaise($employee->fresh(), $user->id, '2026-10-11', 1_600_000);
    pdCompute($tenant->id);
    $next = pdLive($employee->id);
    test()->postJson("/api/v1/payroll/pay-differences/{$next->public_id}/settle", ['note' => 'REF-1'])->assertOk();
    expect(AuditLog::query()->where('action', 'payroll.pay_difference_settled')->count())->toBe(1);

    test()->getJson('/api/v1/payroll/pay-differences?status=settled')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.employee_public_id', $employee->public_id);
});

it('lists the live differences, filtered to the people a run pays', function () {
    [$tenant, $user, $employee] = pdApprovedOctober();
    pdRaise($employee, $user->id, '2026-10-10', 1_500_000);
    pdCompute($tenant->id);
    $november = pdRun($tenant->id, $user->id, '2026-11-01', '2026-11-30');

    test()->getJson("/api/v1/payroll/pay-differences?for_run={$november->public_id}")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.kind', 'underpaid')
        ->assertJsonPath('data.0.gross_cents', 500_000);
    test()->getJson('/api/v1/payroll/pay-differences?employee=nobody')->assertOk()->assertJsonCount(0, 'data');
});

it('keeps another organisation out of the differences', function () {
    [$tenant, $user, $employee] = pdApprovedOctober();
    pdRaise($employee, $user->id, '2026-10-10', 1_500_000);
    pdCompute($tenant->id);
    $row = pdLive($employee->id);

    $otherTenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $otherTenant);

    test()->postJson("/api/v1/payroll/pay-differences/{$row->public_id}/settle", ['note' => 'x'])->assertNotFound();
    test()->postJson("/api/v1/payroll/pay-differences/{$row->public_id}/dismiss", ['note' => 'x'])->assertNotFound();
    test()->getJson('/api/v1/payroll/pay-differences')->assertOk()->assertJsonCount(0, 'data');
    expect(PayrollPayDifference::withoutGlobalScopes()->find($row->id)->status)->toBe('open');
});

it('refuses settling to someone who may not approve payroll', function () {
    [$tenant, $user, $employee] = pdApprovedOctober();
    pdRaise($employee, $user->id, '2026-10-10', 1_500_000);
    pdCompute($tenant->id);
    $row = pdLive($employee->id);

    actingAsUser(['role' => UserRole::EMPLOYEE], $tenant);

    test()->postJson("/api/v1/payroll/pay-differences/{$row->public_id}/settle", ['note' => 'x'])->assertForbidden();
    test()->getJson('/api/v1/payroll/pay-differences')->assertForbidden();
});

it('flags a difference from an entry whose log is older than 1.9 as a rebuilt trail', function () {
    $this->travelTo(IlluminateCarbon::parse('2026-11-05 09:00:00', 'UTC'));
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'salary_cents' => 1_000_000, 'hire_date' => '2024-01-01']);
    Queue::fake();
    $run = pdRun($tenant->id, $user->id, '2026-10-01', '2026-10-31');
    $entry = PayrollEntry::query()->where('payroll_run_id', $run->id)->sole();
    $log = $entry->calculation_log;
    $log['version'] = '1.8';
    DB::table('payroll_entries')->where('id', $entry->id)->update(['calculation_log' => json_encode($log)]);
    app(PayrollEngine::class)->approve($run, $user->id);

    pdRaise($employee, $user->id, '2026-10-10', 1_500_000);
    pdCompute($tenant->id);

    expect(pdLive($employee->id)->detail['trail_rebuilt_at_release'])->toBeTrue()
        ->and(pdLive($employee->id)->gross_cents)->toBe(500_000);
});

it('does nothing for an employee with no released entries', function () {
    $this->travelTo(IlluminateCarbon::parse('2026-11-05 09:00:00', 'UTC'));
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'salary_cents' => 1_000_000, 'hire_date' => '2024-01-01']);
    Queue::fake();

    app(PayDifferenceRecorder::class)->flag($employee, '2020-01-01');

    expect(PayrollPayDifference::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('takes the whole stored net as overpaid when a loan finished at approval and the leaving date moves before the month', function () {
    $this->travelTo(IlluminateCarbon::parse('2026-11-05 09:00:00', 'UTC'));
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'salary_cents' => 1_000_000, 'hire_date' => '2024-01-01']);
    EmployeeLoan::factory()->create([
        'tenant_id' => $tenant->id, 'employee_id' => $employee->id, 'amount_cents' => 50_000,
        'remaining_cents' => 50_000, 'monthly_deduction_cents' => 50_000, 'start_date' => '2026-01-01',
    ]);
    Queue::fake();
    $run = pdRun($tenant->id, $user->id, '2026-10-01', '2026-10-31');
    app(PayrollEngine::class)->approve($run, $user->id);
    $entry = PayrollEntry::query()->where('payroll_run_id', $run->id)->sole();
    expect($entry->other_deductions_cents)->toBe(50_000);

    $employee->update(['status' => EmployeeStatus::TERMINATED, 'termination_date' => '2026-09-15']);
    pdCompute($tenant->id);

    $row = pdLive($employee->id);
    expect($row->net_cents)->toBe(-$entry->net_cents)
        ->and($row->gross_cents)->toBe(-$entry->gross_cents);
});

it('records only the real delta after a settlement even when something else has drifted since', function () {
    [$tenant, $user, $employee] = pdApprovedOctober();
    pdRaise($employee, $user->id, '2026-10-10', 1_500_000);
    pdCompute($tenant->id);
    test()->postJson('/api/v1/payroll/pay-differences/'.pdLive($employee->id)->public_id.'/settle', ['note' => 'paid'])->assertOk();

    PayrollRule::factory()->fixed(100_000)->create(['tenant_id' => $tenant->id, 'name' => 'Transport']);
    pdRaise($employee->fresh(), $user->id, '2026-10-12', 1_700_000);
    pdCompute($tenant->id);

    expect(pdLive($employee->id)->gross_cents)->toBe(200_000);
});

it('refuses to void a run with settled differences unless the count is acknowledged, and says so in the audit', function () {
    [$tenant, $user, $employee, $october] = pdApprovedOctober();
    pdRaise($employee, $user->id, '2026-10-10', 1_500_000);
    pdCompute($tenant->id);
    test()->postJson('/api/v1/payroll/pay-differences/'.pdLive($employee->id)->public_id.'/settle', ['note' => 'paid'])->assertOk();

    test()->postJson("/api/v1/payroll/runs/{$october->public_id}/void", ['reason' => 'redo'])
        ->assertStatus(422)
        ->assertJsonPath('type', 'https://ethr.et/errors/settled-pay-differences-unacknowledged')
        ->assertJsonPath('settled_pay_differences', 1);
    test()->postJson("/api/v1/payroll/runs/{$october->public_id}/void", ['reason' => 'redo', 'acknowledged_settled_pay_differences' => 3])
        ->assertStatus(422);
    expect($october->fresh()->status)->toBe('approved');

    test()->postJson("/api/v1/payroll/runs/{$october->public_id}/void", ['reason' => 'redo', 'acknowledged_settled_pay_differences' => 1])
        ->assertOk();

    expect($october->fresh()->status)->toBe('voided')
        ->and(PayrollPayDifference::query()->where('status', 'settled')->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'payroll.voided')->latest('id')->first()->payload['acknowledged_settled_pay_differences'])->toBe(1);
});

it('records a back-dated raise as underpaid for someone suspended since', function () {
    [$tenant, $user, $employee] = pdApprovedOctober();
    $employee->update(['status' => EmployeeStatus::SUSPENDED]);

    pdRaise($employee->fresh(), $user->id, '2026-10-10', 1_500_000);
    pdCompute($tenant->id);

    $row = pdLive($employee->id);
    expect($row->kind)->toBe('underpaid')
        ->and($row->gross_cents)->toBe(500_000);
});

it('makes the next approval acknowledge a leaver who has no entry in it', function () {
    [$tenant, $user, $leaver] = pdApprovedOctober();
    Employee::factory()->create(['tenant_id' => $tenant->id, 'salary_cents' => 800_000, 'hire_date' => '2024-01-01']);

    $leaver->update(['status' => EmployeeStatus::TERMINATED, 'termination_date' => '2026-09-15']);
    pdCompute($tenant->id);
    expect(pdLive($leaver->id)->status)->toBe('open');

    $november = pdRun($tenant->id, $user->id, '2026-11-01', '2026-11-30');
    expect(PayrollEntry::query()->where('payroll_run_id', $november->id)->where('employee_id', $leaver->id)->exists())->toBeFalse();

    test()->putJson("/api/v1/payroll/runs/{$november->public_id}/approve")
        ->assertStatus(422)
        ->assertJsonPath('type', 'https://ethr.et/errors/pay-differences-unacknowledged')
        ->assertJsonPath('pay_differences', 1);
    test()->putJson("/api/v1/payroll/runs/{$november->public_id}/approve", ['acknowledged_pay_differences' => 1])->assertOk();
});

it('counts the open differences again under the approval lock', function () {
    [$tenant, $user, $employee] = pdApprovedOctober();
    pdRaise($employee, $user->id, '2026-10-10', 1_500_000);
    pdCompute($tenant->id);
    $november = pdRun($tenant->id, $user->id, '2026-11-01', '2026-11-30');

    expect(fn () => app(PayrollEngine::class)->approve($november, $user->id, 0))
        ->toThrow(PayrollRunRefused::class);
    expect($november->fresh()->status)->toBe('completed');

    app(SalaryHistoryWriter::class)->record($employee->fresh(), 1_500_000, '2026-10-11', 'edit', null, $user->id);
    expect(fn () => app(PayrollEngine::class)->approve($november, $user->id, 1))
        ->toThrow(PayrollRunRefused::class, __('payroll.pay_differences_pending'));
});

it('measures the net difference with the loans and cost sharing the month deducted, not today\'s', function () {
    [$tenant, $user, $employee] = pdApprovedOctober();
    EmployeeCostSharing::factory()->create([
        'tenant_id' => $tenant->id, 'employee_id' => $employee->id, 'deduction_rate_percent' => 10,
        'total_obligation_cents' => 5_000_000, 'outstanding_cents' => 5_000_000, 'started_on' => '2026-11-01',
    ]);

    pdRaise($employee, $user->id, '2026-10-10', 1_500_000);
    pdCompute($tenant->id);

    $row = pdLive($employee->id);
    $paid = $row->detail['paid_side'];
    $due = $row->detail['due_side'];
    expect($row->detail['net_is_estimate'])->toBeFalse()
        ->and($row->net_cents)->toBe(
            $row->gross_cents - ($due['income_tax_cents'] - $paid['income_tax_cents']) - ($due['employee_pension_cents'] - $paid['employee_pension_cents'])
        );
});

it('leaves a row pending when it cannot be worked out, and answers the approval with a 422, not a 500', function () {
    [$tenant, $user, $employee, $october] = pdApprovedOctober();
    $other = Employee::factory()->create(['tenant_id' => $tenant->id, 'salary_cents' => 900_000, 'hire_date' => '2024-01-01']);
    $second = pdRun($tenant->id, $user->id, '2026-09-01', '2026-09-30');
    app(PayrollEngine::class)->approve($second, $user->id);
    pdRaise($employee, $user->id, '2026-09-20', 1_500_000);
    pdRaise($other, $user->id, '2026-09-20', 1_100_000);

    $broken = PayrollEntry::query()->where('payroll_run_id', $october->id)->where('employee_id', $employee->id)->sole();
    $recorder = new class(app(PayrollEngine::class), $broken->id) extends PayDifferenceRecorder
    {
        public function __construct(PayrollEngine $engine, private readonly int $failingEntry)
        {
            parent::__construct($engine);
        }

        protected function counterfactualFor(PayrollEntry $entry, array &$prepared): array
        {
            if ($entry->id === $this->failingEntry) {
                throw new RuntimeException('cannot be worked out');
            }

            return parent::counterfactualFor($entry, $prepared);
        }
    };
    app()->instance(PayDifferenceRecorder::class, $recorder);

    $november = pdRun($tenant->id, $user->id, '2026-11-01', '2026-11-30');
    test()->putJson("/api/v1/payroll/runs/{$november->public_id}/approve")
        ->assertStatus(422)
        ->assertJsonPath('type', 'https://ethr.et/errors/pay-differences-pending');

    expect(PayrollPayDifference::query()->where('payroll_entry_id', $broken->id)->sole()->status)->toBe('pending')
        ->and(PayrollPayDifference::query()->where('status', 'open')->count())->toBeGreaterThan(0);
});
