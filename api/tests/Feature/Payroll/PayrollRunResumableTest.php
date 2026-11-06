<?php

declare(strict_types=1);

use App\Jobs\ProcessPayrollJob;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\PayrollEntry;
use App\Models\PayrollRun;
use App\Models\Tenant;
use App\Services\CurrentTenant;
use App\Services\Payroll\PayrollEngine;
use Carbon\Carbon;
use Illuminate\Support\Facades\Queue;

// A-Z review, payroll run time (2026-11-06). A run was one transaction over
// every employee, inside one web request on a host that stops a request after
// 30 s of CPU. Measured on MariaDB: 1,000 people take 8 s of CPU, 2,000 take
// 18 s, and a host twice as slow stops the run at about 1,600, with nothing
// the organisation can do about it: every resubmission dies the same way. A
// run now commits in batches and, when the request's time is running out,
// hands the rest to the next job. Nobody is paid twice, no one is dropped, and
// the totals are the sums of what was written.

function resumableRun(int $people, int $withoutSalary = 0): PayrollRun
{
    $tenant = createTenant();
    app(CurrentTenant::class)->set($tenant);
    $admin = createUser([], $tenant);
    Employee::factory()->count($people)->create(['tenant_id' => $tenant->id, 'salary_cents' => 1_000_000, 'hire_date' => '2024-01-01']);
    if ($withoutSalary > 0) {
        Employee::query()->where('tenant_id', $tenant->id)->orderBy('id')->limit($withoutSalary)->update(['salary_cents' => 0]);
    }

    return app(PayrollEngine::class)->begin($tenant->id, Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31'), $admin->id)->run;
}

it('stops at the deadline having paid at least one batch, and stays processing', function () {
    $run = resumableRun(250);

    $paused = app(PayrollEngine::class)->runEntries($run, microtime(true) - 1);

    expect($paused->status)->toBe('processing')
        ->and(PayrollEntry::query()->where('payroll_run_id', $run->id)->count())->toBe(100)
        ->and($paused->employee_count)->toBe(0);
});

it('finishes where it stopped, with every employee once and totals that are the sums', function () {
    $run = resumableRun(250);
    $engine = app(PayrollEngine::class);

    $engine->runEntries($run, microtime(true) - 1);
    $engine->runEntries($run->refresh(), microtime(true) - 1);
    $done = $engine->runEntries($run->refresh());

    $entries = PayrollEntry::query()->where('payroll_run_id', $run->id)->get();

    expect($done->status)->toBe('completed')
        ->and($entries)->toHaveCount(250)
        ->and($entries->pluck('employee_id')->unique())->toHaveCount(250)
        ->and($done->employee_count)->toBe(250)
        ->and($done->gross_total_cents)->toBe((int) $entries->sum('gross_cents'))
        ->and($done->net_total_cents)->toBe((int) $entries->sum('net_cents'))
        ->and($done->tax_total_cents)->toBe((int) $entries->sum('income_tax_cents'));
});

it('counts the people it skipped for having no salary once, however often it stopped', function () {
    $run = resumableRun(230, withoutSalary: 20);
    $engine = app(PayrollEngine::class);

    $engine->runEntries($run, microtime(true) - 1);
    $done = $engine->runEntries($run->refresh());

    expect($done->status)->toBe('completed')
        ->and($done->skipped_no_salary)->toBe(20)
        ->and($done->employee_count)->toBe(210);
});

it('pays the same as a run that never stopped', function () {
    $whole = resumableRun(150);
    $wholeDone = app(PayrollEngine::class)->runEntries($whole);

    $split = resumableRun(150);
    app(PayrollEngine::class)->runEntries($split, microtime(true) - 1);
    $splitDone = app(PayrollEngine::class)->runEntries($split->refresh());

    expect($splitDone->gross_total_cents)->toBe($wholeDone->gross_total_cents)
        ->and($splitDone->net_total_cents)->toBe($wholeDone->net_total_cents)
        ->and($splitDone->tax_total_cents)->toBe($wholeDone->tax_total_cents);
});

it('hands the rest of a run to a new job, and announces it only when it is done', function () {
    Queue::fake();
    $run = resumableRun(250);
    config(['payroll.request_budget_seconds' => 0.0001]);

    (new ProcessPayrollJob($run->id, $run->tenant_id))->handle(app(PayrollEngine::class));

    Queue::assertPushed(ProcessPayrollJob::class, fn (ProcessPayrollJob $job): bool => $job->payrollRunId === $run->id);
    expect($run->refresh()->status)->toBe('processing')
        ->and(AuditLog::query()->withoutGlobalScopes()->where('action', 'payroll.processed')->count())->toBe(0);

    config(['payroll.request_budget_seconds' => null]);
    (new ProcessPayrollJob($run->id, $run->tenant_id))->handle(app(PayrollEngine::class));

    expect($run->refresh()->status)->toBe('completed')
        ->and(AuditLog::query()->withoutGlobalScopes()->where('action', 'payroll.processed')->count())->toBe(1);
});

it('leaves no partial entries under a run that fails', function () {
    $run = resumableRun(250);
    app(PayrollEngine::class)->runEntries($run, microtime(true) - 1);
    expect(PayrollEntry::query()->where('payroll_run_id', $run->id)->count())->toBe(100);

    // As the next worker runs it for a job whose worker was lost: no tenant is
    // set, because handle() never ran there.
    app(CurrentTenant::class)->forget();
    (new ProcessPayrollJob($run->id, $run->tenant_id))->failed(new RuntimeException('worker lost'));

    app(CurrentTenant::class)->set(Tenant::query()->find($run->tenant_id));
    expect($run->refresh()->status)->toBe('failed')
        ->and(PayrollEntry::query()->where('payroll_run_id', $run->id)->count())->toBe(0);
});
