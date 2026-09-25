<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Events\PayrollRunFailed;
use App\Jobs\ProcessPayrollJob;
use App\Listeners\NotifyPayrollRunFailed;
use App\Models\Employee;
use App\Models\PayrollEntry;
use App\Models\PayrollRun;
use App\Models\User;
use App\Notifications\PayrollRunFailedNotification;
use App\Services\CurrentTenant;
use App\Services\Payroll\PayrollEngine;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

/**
 * Payroll is computed on the queue, not in the request.
 *
 * The rest of the payroll suite runs under QUEUE_CONNECTION=sync, so the job
 * executes inline and every assertion passes whether or not anything was
 * actually queued. These tests fake the queue, which is the only way to tell
 * the difference.
 *
 * Why it moved: PayrollEngine chunks over every active employee, computing tax,
 * pension, overtime, loans, allowances and cost-sharing per row. docs/CLAUDE.md:858
 * budgets "payroll calculation (500 employees) < 30s" on dedicated hardware — at
 * or past a typical shared host's max_execution_time before any contention. The
 * old failure mode was a 504 partway through, leaving the run at `processing`
 * with no way to know what had been written. Master plan §20, and
 * docs/audit/BASELINE.md §13a.
 */
function payrollPayload(string $key): array
{
    return [
        'period_start' => '2026-06-01',
        'period_end' => '2026-06-30',
        'idempotency_key' => $key,
    ];
}

it('queues the computation instead of running it in the request', function () {
    Queue::fake();

    $tenant = createTenant();
    actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);
    Employee::factory()->create(['tenant_id' => $tenant->id, 'salary_cents' => 500000]);

    $response = test()->postJson(
        "http://{$tenant->subdomain}.ethr.test/api/v1/payroll/process",
        payrollPayload('queued-1')
    );

    // 202 Accepted, not 201 Created: the run exists, the result does not yet.
    $response->assertStatus(202)
        ->assertJsonPath('status', 'processing')
        ->assertJsonPath('was_duplicate', false);

    Queue::assertPushed(ProcessPayrollJob::class, 1);

    // Nothing computed. This is the assertion that fails if the engine is still
    // being called inline — the whole point of the change.
    expect(PayrollEntry::where('tenant_id', $tenant->id)->count())->toBe(0);

    $run = PayrollRun::where('tenant_id', $tenant->id)->firstOrFail();
    expect($run->status)->toBe('processing');
});

it('computes the entries when the job runs', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);
    Employee::factory()->count(3)->create(['tenant_id' => $tenant->id, 'salary_cents' => 500000]);

    Queue::fake();
    test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/payroll/process", payrollPayload('queued-2'));
    $run = PayrollRun::where('tenant_id', $tenant->id)->firstOrFail();

    expect(PayrollEntry::where('payroll_run_id', $run->id)->count())->toBe(0);

    // Run the job the way the worker would.
    (new ProcessPayrollJob($run->id, $run->tenant_id))->handle(app(PayrollEngine::class));

    $run->refresh();

    expect($run->status)->toBe('completed')
        ->and($run->employee_count)->toBe(3)
        ->and(PayrollEntry::where('payroll_run_id', $run->id)->count())->toBe(3);

    // Convention 11 — every entry keeps its full calculation trace. Moving the
    // work to a job must not quietly drop it.
    $entry = PayrollEntry::where('payroll_run_id', $run->id)->first();
    expect($entry->calculation_log)->not->toBeNull();
});

it('does not queue a second job when an idempotency key is replayed', function () {
    Queue::fake();

    $tenant = createTenant();
    actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);
    Employee::factory()->create(['tenant_id' => $tenant->id, 'salary_cents' => 500000]);

    $url = "http://{$tenant->subdomain}.ethr.test/api/v1/payroll/process";

    test()->postJson($url, payrollPayload('replay-me'))->assertStatus(202);
    test()->postJson($url, payrollPayload('replay-me'))
        ->assertStatus(200)
        ->assertJsonPath('was_duplicate', true);

    // Convention 10. Idempotency has to be enforced *before* dispatch — a
    // second job on the same run would append a duplicate set of entries, which
    // no amount of checking afterwards could undo.
    Queue::assertPushed(ProcessPayrollJob::class, 1);
    expect(PayrollRun::where('tenant_id', $tenant->id)->count())->toBe(1);
});

it('refuses to recompute a run that is no longer processing', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);
    Employee::factory()->create(['tenant_id' => $tenant->id, 'salary_cents' => 500000]);

    Queue::fake();
    test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/payroll/process", payrollPayload('guard-1'));
    $run = PayrollRun::where('tenant_id', $tenant->id)->firstOrFail();

    $engine = app(PayrollEngine::class);
    (new ProcessPayrollJob($run->id, $run->tenant_id))->handle($engine);
    $countAfterFirst = PayrollEntry::where('payroll_run_id', $run->id)->count();

    // A worker that picks the same job up twice — because retry_after elapsed,
    // or because someone re-queued it by hand — must not append a second set of
    // entries. $tries = 1 makes this unlikely; the status guard makes it safe.
    (new ProcessPayrollJob($run->id, $run->tenant_id))->handle($engine);

    expect(PayrollEntry::where('payroll_run_id', $run->id)->count())->toBe($countAfterFirst);
});

it('marks the run failed so it cannot sit at processing forever', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);

    Queue::fake();
    test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/payroll/process", payrollPayload('doomed'));
    $run = PayrollRun::where('tenant_id', $tenant->id)->firstOrFail();

    expect($run->status)->toBe('processing');

    (new ProcessPayrollJob($run->id, $run->tenant_id))->failed(new RuntimeException('database went away'));

    $run->refresh();

    // Without this a crashed run is indistinguishable from one still working —
    // the same ambiguity the 504 produced, which is half the reason the job
    // exists.
    expect($run->status)->toBe('failed');
});

it('leaves a finished run alone when failed() fires late', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);
    Employee::factory()->create(['tenant_id' => $tenant->id, 'salary_cents' => 500000]);

    Queue::fake();
    test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/payroll/process", payrollPayload('late-fail'));
    $run = PayrollRun::where('tenant_id', $tenant->id)->firstOrFail();

    (new ProcessPayrollJob($run->id, $run->tenant_id))->handle(app(PayrollEngine::class));
    expect($run->refresh()->status)->toBe('completed');

    (new ProcessPayrollJob($run->id, $run->tenant_id))->failed(new RuntimeException('a stale worker reporting in'));

    // A completed run must not be flipped to failed by a late signal from a
    // worker that already lost its lease.
    expect($run->refresh()->status)->toBe('completed');
});

/**
 * A crashed payroll run told nobody.
 *
 * `ProcessPayrollJob::failed()` marked the run `failed`, wrote a `Log::error`
 * and an `AuditLog` `payroll.failed` record — so a crashed run stopped being
 * indistinguishable from a running one, which was the important half. But
 * docs/CLAUDE.md's queue-recovery table specifies "mark payroll run as failed
 * with error details, **notify tenant admin**", and there was no notification
 * on this path at all.
 *
 * It matters more here than on any other queue. `$tries = 1` is deliberate: a
 * failed run must be inspected and re-submitted by a human rather than silently
 * retried. The design already assumed someone finds out, and the only way they
 * found out was opening the dashboard — for a monthly run, potentially days.
 */
describe('a failed run reaches the people who can act on it', function () {
    it('notifies finance and tenant admins, with the reason', function () {
        Notification::fake();

        $tenant = createTenant();
        $financeAdmin = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => UserRole::FINANCE_ADMIN->value,
        ]);
        $tenantAdmin = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => UserRole::TENANT_ADMIN->value,
        ]);
        $run = PayrollRun::factory()->create([
            'tenant_id' => $tenant->id,
            'status' => 'processing',
            'period_label' => 'March 2026',
        ]);

        (new NotifyPayrollRunFailed)->handle(
            new PayrollRunFailed($run, 'tax table missing for 2026'),
        );

        foreach ([$financeAdmin, $tenantAdmin] as $admin) {
            Notification::assertSentTo($admin, PayrollRunFailedNotification::class, function ($notification) use ($admin) {
                $mail = $notification->toMail($admin);
                $rendered = $mail->subject.' '.implode(' ', $mail->introLines);

                // The reason is included, unlike the scheduled-report and digest
                // failure notices. The audience decides it: these go to
                // finance_admin and tenant_admin users of the run's own tenant,
                // already entitled to the run's detail.
                expect($rendered)->toContain('tax table missing for 2026');
                expect($rendered)->toContain('March 2026');
                expect($mail->subject)->not->toStartWith('payroll.');

                return true;
            });
        }
    });

    it('does not reach another tenant, even from a worker with no tenant resolved', function () {
        Notification::fake();

        $owner = createTenant();
        $other = createTenant();
        $outsider = User::factory()->create([
            'tenant_id' => $other->id,
            'role' => UserRole::FINANCE_ADMIN->value,
        ]);
        $run = PayrollRun::factory()->create(['tenant_id' => $owner->id, 'status' => 'processing']);

        app(CurrentTenant::class)->forget();

        (new NotifyPayrollRunFailed)->handle(new PayrollRunFailed($run, 'boom'));

        // Dropping the global scope must not widen the query — the explicit
        // predicate is what keeps the other tenant's finance admin out of it.
        Notification::assertNotSentTo($outsider, PayrollRunFailedNotification::class);
    });

    it('fires the event only when this call is the one that marked the run failed', function () {
        Event::fake([PayrollRunFailed::class]);

        $tenant = createTenant();

        // Already failed, so failed() takes its early return: a job whose
        // failure handler runs twice, or a run someone else already marked,
        // must not notify again.
        $already = PayrollRun::factory()->create(['tenant_id' => $tenant->id, 'status' => 'failed']);
        (new ProcessPayrollJob($already->id, $tenant->id))->failed(new RuntimeException('boom'));
        Event::assertNotDispatched(PayrollRunFailed::class);

        $live = PayrollRun::factory()->create(['tenant_id' => $tenant->id, 'status' => 'processing']);
        (new ProcessPayrollJob($live->id, $tenant->id))->failed(new RuntimeException('tax table missing'));

        Event::assertDispatched(
            PayrollRunFailed::class,
            fn (PayrollRunFailed $e) => $e->payrollRun->is($live) && $e->reason === 'tax table missing',
        );
        expect($live->refresh()->status)->toBe('failed');
    });
});
