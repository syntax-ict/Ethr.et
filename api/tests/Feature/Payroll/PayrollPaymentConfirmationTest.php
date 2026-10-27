<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\PayrollEntry;
use App\Models\PayrollRun;
use App\Models\Tenant;
use App\Services\Payroll\PayrollEngine;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Upgrade 3 of the payroll plan: a run's last state is `paid`, recorded by a
 * person with the date the money left and the bank's reference; and once a
 * run is released its amounts cannot change, in the database itself.
 */
function paidRunFor(Tenant $tenant, int $userId, string $status = 'approved'): PayrollRun
{
    Employee::factory()->create([
        'tenant_id' => $tenant->id, 'salary_cents' => 1000000, 'hire_date' => '2020-01-01', 'status' => 'confirmed',
    ]);
    $engine = app(PayrollEngine::class);
    $run = $engine->process($tenant->id, Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31'), $userId)->run;

    return $status === 'approved' ? $engine->approve($run, $userId) : $run;
}

function paidUrl(Tenant $tenant, PayrollRun $run): string
{
    return "http://{$tenant->subdomain}.ethr.test/api/v1/payroll/runs/{$run->public_id}/paid";
}

describe('confirming payment', function () {
    it('records who paid, when and the bank reference, and audits it', function () {
        $tenant = createTenant();
        $user = actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);
        $run = paidRunFor($tenant, $user->id);

        $this->putJson(paidUrl($tenant, $run), ['paid_on' => '2026-10-26', 'payment_reference' => 'CBE-BATCH-0042'])
            ->assertOk()
            ->assertJsonPath('status', 'paid')
            ->assertJsonPath('paid_on', '2026-10-26')
            ->assertJsonPath('payment_reference', 'CBE-BATCH-0042')
            ->assertJsonMissingPath('paid_by');

        $fresh = $run->fresh();
        expect($fresh->status)->toBe('paid')
            ->and($fresh->paid_by)->toBe($user->id)
            ->and($fresh->paid_at)->not->toBeNull()
            ->and(AuditLog::query()->where('action', 'payroll.paid')->exists())->toBeTrue();
    });

    it('refuses a run that is not approved yet', function () {
        $tenant = createTenant();
        $user = actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);
        $run = paidRunFor($tenant, $user->id, 'completed');

        $this->putJson(paidUrl($tenant, $run), ['paid_on' => '2026-10-26'])->assertUnprocessable();
        expect($run->fresh()->status)->toBe('completed');
    });

    it('refuses a payment date in the future', function () {
        $tenant = createTenant();
        $user = actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);
        $run = paidRunFor($tenant, $user->id);

        $this->putJson(paidUrl($tenant, $run), ['paid_on' => now()->addDays(3)->toDateString()])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['paid_on']);
    });

    it('needs payroll.markPaid', function () {
        $tenant = createTenant();
        $finance = createUser(['role' => UserRole::FINANCE_ADMIN], $tenant);
        $run = paidRunFor($tenant, $finance->id);
        actingAsUser(['role' => UserRole::EMPLOYEE], $tenant);

        $this->putJson(paidUrl($tenant, $run), ['paid_on' => '2026-10-26'])->assertForbidden();
    });

    it('will not void a paid run: a correction goes through the next run', function () {
        $tenant = createTenant();
        $user = actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);
        $run = paidRunFor($tenant, $user->id);
        $this->putJson(paidUrl($tenant, $run), ['paid_on' => '2026-10-26'])->assertOk();

        $this->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/payroll/runs/{$run->public_id}/void", ['reason' => 'Wrong month entirely'])
            ->assertUnprocessable();
        expect($run->fresh()->status)->toBe('paid');
    });

    it('still shows employees their payslips once the run is paid', function () {
        $tenant = createTenant();
        $user = actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);
        $run = paidRunFor($tenant, $user->id);
        $this->putJson(paidUrl($tenant, $run), ['paid_on' => '2026-10-26'])->assertOk();

        $entry = PayrollEntry::query()->where('payroll_run_id', $run->id)->sole();
        $employeeUser = createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $entry->employee_id], $tenant);
        $this->actingAs($employeeUser);

        $this->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/payroll/payslips/my")
            ->assertOk()
            ->assertJsonCount(1, 'data');
    });
});

describe('frozen amounts', function () {
    it('refuses a raw update to an approved entry\'s amount', function () {
        $tenant = createTenant();
        $user = actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);
        $run = paidRunFor($tenant, $user->id);

        expect(fn () => DB::table('payroll_entries')->where('payroll_run_id', $run->id)->update(['net_cents' => 1]))
            ->toThrow(QueryException::class);
    });

    it('refuses the same through the model', function () {
        $tenant = createTenant();
        $user = actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);
        $run = paidRunFor($tenant, $user->id);
        $entry = PayrollEntry::query()->where('payroll_run_id', $run->id)->sole();

        expect(fn () => $entry->update(['gross_cents' => 1]))->toThrow(LogicException::class);
    });

    it('still lets a run that is only calculated be corrected', function () {
        $tenant = createTenant();
        $user = actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);
        $run = paidRunFor($tenant, $user->id, 'completed');

        expect(DB::table('payroll_entries')->where('payroll_run_id', $run->id)->update(['net_cents' => 1]))->toBe(1);
    });

    it('refuses a raw change to a released run\'s totals, or a voided run coming back', function () {
        $tenant = createTenant();
        $user = actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);
        $run = paidRunFor($tenant, $user->id);

        expect(fn () => DB::table('payroll_runs')->where('id', $run->id)->update(['net_total_cents' => 1]))
            ->toThrow(QueryException::class);

        app(PayrollEngine::class)->void($run, $user->id, 'Testing the freeze');
        expect(fn () => DB::table('payroll_runs')->where('id', $run->id)->update(['status' => 'approved']))
            ->toThrow(QueryException::class);
    });

    it('refuses marking a run paid that skipped approval', function () {
        $tenant = createTenant();
        $user = actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);
        $run = paidRunFor($tenant, $user->id, 'completed');

        expect(fn () => DB::table('payroll_runs')->where('id', $run->id)->update(['status' => 'paid']))
            ->toThrow(QueryException::class);
    });
});
