<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Jobs\ProcessPayrollJob;
use App\Models\AuditLog;
use App\Models\PayrollEntry;
use App\Models\PayrollRun;
use App\Services\Payroll\PayrollEngine;
use Carbon\Carbon;
use Tests\Support\IdempotencyFixtures as Fx;

/*
 * Payroll: the same request, sent a second time, read back from the database.
 * Sequential repeats only; the races are in tests/Concurrency. Where a repeat
 * is answered with an error, the test says so in its name and the item is in
 * findings.php.
 */

it('releases pay once when approve is sent twice, today: the second approve is answered 422 invalid-state although the first succeeded', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);
    $employee = Fx::employeeWithBalances($tenant);
    $run = Fx::processRun($tenant, $user->id);

    test()->putJson(Fx::runUrl($tenant, $run, 'approve'))->assertOk()->assertJsonPath('status', 'approved');
    $approvedAt = $run->fresh()->approved_at;

    Carbon::setTestNow(now()->addMinutes(5));

    test()->putJson(Fx::runUrl($tenant, $run, 'approve'))
        ->assertStatus(422)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('type', 'https://ethr.et/errors/invalid-state')
        ->assertJsonPath('title', 'Invalid State')
        ->assertJsonPath('detail', __('payroll.not_completed'));

    $fresh = $run->fresh();
    expect($fresh->status)->toBe('approved')
        ->and($fresh->approved_by)->toBe($user->id)
        ->and($fresh->approved_at->equalTo($approvedAt))->toBeTrue()
        ->and(Fx::loanRemaining($employee))->toBe(220000)
        ->and(Fx::costSharingOutstanding($employee))->toBe(4920000)
        ->and(AuditLog::where('action', 'payroll.balances_applied')->count())->toBe(1)
        ->and(AuditLog::where('action', 'payroll.approved')->count())->toBe(1);
});

it('records payment once when mark paid is sent twice, today: the second is answered 422 invalid-state and the first reference stands', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);
    Fx::employeeWithBalances($tenant);
    $run = Fx::processRun($tenant, $user->id);
    test()->putJson(Fx::runUrl($tenant, $run, 'approve'))->assertOk();

    $paid = ['paid_on' => '2026-07-02', 'payment_reference' => 'BANK-REF-1', 'payment_note' => 'first'];
    test()->putJson(Fx::runUrl($tenant, $run, 'paid'), $paid)->assertOk()->assertJsonPath('status', 'paid');
    $paidAt = $run->fresh()->paid_at;

    Carbon::setTestNow(now()->addMinutes(5));

    test()->putJson(Fx::runUrl($tenant, $run, 'paid'), ['paid_on' => '2026-07-03', 'payment_reference' => 'BANK-REF-2', 'payment_note' => 'second'])
        ->assertStatus(422)
        ->assertJsonPath('type', 'https://ethr.et/errors/invalid-state')
        ->assertJsonPath('detail', __('payroll.payment_not_approved'));

    $fresh = $run->fresh();
    expect($fresh->status)->toBe('paid')
        ->and($fresh->paid_on->toDateString())->toBe('2026-07-02')
        ->and($fresh->payment_reference)->toBe('BANK-REF-1')
        ->and($fresh->payment_note)->toBe('first')
        ->and($fresh->paid_at->equalTo($paidAt))->toBeTrue()
        ->and(AuditLog::where('action', 'payroll.paid')->count())->toBe(1);
});

it('gives the balances back once when void is sent twice, today: the second void is answered 422 invalid-state', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);
    $employee = Fx::employeeWithBalances($tenant);
    $run = Fx::processRun($tenant, $user->id);
    test()->putJson(Fx::runUrl($tenant, $run, 'approve'))->assertOk();
    expect(Fx::loanRemaining($employee))->toBe(220000);

    test()->postJson(Fx::runUrl($tenant, $run, 'void'), ['reason' => 'Wrong allowance'])->assertOk()->assertJsonPath('status', 'voided');
    expect(Fx::loanRemaining($employee))->toBe(240000);

    test()->postJson(Fx::runUrl($tenant, $run, 'void'), ['reason' => 'Wrong allowance'])
        ->assertStatus(422)
        ->assertJsonPath('type', 'https://ethr.et/errors/invalid-state')
        ->assertJsonPath('detail', __('payroll.cannot_void'));

    expect($run->fresh()->status)->toBe('voided')
        ->and(Fx::loanRemaining($employee))->toBe(240000)
        ->and(Fx::costSharingOutstanding($employee))->toBe(5000000)
        ->and(AuditLog::where('action', 'payroll.balances_reversed')->count())->toBe(1)
        ->and(AuditLog::where('action', 'payroll.voided')->count())->toBe(1);
});

it('voids a completed run once and leaves the balances alone when void is sent twice', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);
    $employee = Fx::employeeWithBalances($tenant);
    $run = Fx::processRun($tenant, $user->id);

    test()->postJson(Fx::runUrl($tenant, $run, 'void'), ['reason' => 'Wrong period'])->assertOk();
    test()->postJson(Fx::runUrl($tenant, $run, 'void'), ['reason' => 'Wrong period'])->assertStatus(422);

    expect($run->fresh()->status)->toBe('voided')
        ->and(Fx::loanRemaining($employee))->toBe(240000)
        ->and(Fx::costSharingOutstanding($employee))->toBe(5000000)
        ->and(AuditLog::where('action', 'payroll.balances_reversed')->count())->toBe(0);
});

it('writes one set of entries when ProcessPayrollJob is handled twice for one run', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);
    foreach (range(1, 3) as $i) {
        Fx::employeeWithBalances($tenant);
    }

    $run = app(PayrollEngine::class)->begin($tenant->id, Carbon::parse('2026-06-01'), Carbon::parse('2026-06-30'), $user->id)->run;
    expect($run->status)->toBe('processing');

    $handle = fn () => (new ProcessPayrollJob($run->id, $tenant->id))->handle(app(PayrollEngine::class));

    $handle();
    $afterFirst = PayrollEntry::where('payroll_run_id', $run->id)->orderBy('id')->get(['id', 'employee_id', 'gross_cents', 'net_cents']);
    $totals = $run->fresh()->only(['status', 'employee_count', 'gross_total_cents', 'net_total_cents']);

    $handle();
    $afterSecond = PayrollEntry::where('payroll_run_id', $run->id)->orderBy('id')->get(['id', 'employee_id', 'gross_cents', 'net_cents']);

    expect($afterFirst)->toHaveCount(3)
        ->and($afterSecond->toArray())->toBe($afterFirst->toArray())
        ->and($run->fresh()->only(['status', 'employee_count', 'gross_total_cents', 'net_total_cents']))->toBe($totals)
        ->and($totals['status'])->toBe('completed')
        ->and($totals['gross_total_cents'])->toBe(3 * 800000)
        ->and(AuditLog::where('action', 'payroll.processed')->count())->toBe(1)
        ->and(PayrollRun::count())->toBe(1);
});
