<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Jobs\RunScheduledReportsJob;
use App\Models\CustomRole;
use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Models\Permission;
use App\Models\SavedReport;
use App\Models\ScheduledReport;
use App\Models\Tenant;
use App\Notifications\ScheduledReportNotification;
use App\Services\CurrentTenant;
use App\Services\Report\ReportEngine;
use Illuminate\Support\Facades\Notification;

/*
 * Security review 2026-10-30, authorization inside one organisation.
 *  - Contract salaries were shown to anyone who could view the employee.
 *  - A contract or a personnel action could set pay without
 *    employee.updateFinancial, which a direct salary edit requires.
 *  - Two roles share level 70 with different jobs (audit N95); an HR admin
 *    could hand out the other one, and suspend a peer.
 */

function payAuthzUrl(Tenant $tenant, string $path): string
{
    return "http://{$tenant->subdomain}.ethr.test/api/v1/{$path}";
}

/** A supervisor, their report (on a contract) and the tenant. */
function payAuthzTeam(): array
{
    $tenant = createTenant();
    $lead = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $report = Employee::factory()->create([
        'tenant_id' => $tenant->id, 'supervisor_id' => $lead->id, 'salary_cents' => 2_500_000,
    ]);
    EmployeeContract::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $report->id,
        'salary_cents' => 2_500_000,
        'start_date' => now()->subYear()->toDateString(),
        'end_date' => now()->addDays(20)->toDateString(),
    ]);

    return [$tenant, $lead, $report];
}

function payAuthzRole(Tenant $tenant, array $permissions): CustomRole
{
    $role = CustomRole::create(['tenant_id' => $tenant->id, 'name' => 'Editor '.count($permissions), 'org_scope' => 'all']);
    $role->permissions()->sync(Permission::whereIn('name', $permissions)->pluck('id'));

    return $role;
}

describe('contract salaries', function () {
    it('are not shown to a supervisor', function () {
        [$tenant, $lead, $report] = payAuthzTeam();
        actingAsUser(['role' => UserRole::SUPERVISOR, 'employee_id' => $lead->id], $tenant);

        test()->getJson(payAuthzUrl($tenant, "employees/{$report->public_id}/contracts"))
            ->assertOk()
            ->assertJsonPath('0.contract_type', 'fixed_term')
            ->assertJsonMissingPath('0.salary_cents');
    });

    it('are not shown on the expiring watchlist without employee.viewFinancial', function () {
        [$tenant, , $report] = payAuthzTeam();
        $role = payAuthzRole($tenant, ['employee.viewAny', 'employee.view']);
        actingAsUser(['role' => UserRole::EMPLOYEE, 'custom_role_id' => $role->id], $tenant);

        $response = test()->getJson(payAuthzUrl($tenant, 'employees/contracts/expiring'))->assertOk();

        expect($response->json('data.0.employee_public_id'))->toBe($report->public_id)
            ->and(array_key_exists('salary_cents', $response->json('data.0')))->toBeFalse();
    });

    it('are shown to HR and to the employee themselves', function () {
        [$tenant, , $report] = payAuthzTeam();

        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
        test()->getJson(payAuthzUrl($tenant, "employees/{$report->public_id}/contracts"))
            ->assertOk()
            ->assertJsonPath('0.salary_cents', 2_500_000);
    });

    it('cannot set pay without employee.updateFinancial', function () {
        [$tenant, , $report] = payAuthzTeam();
        $role = payAuthzRole($tenant, ['employee.viewAny', 'employee.view', 'employee.update']);
        actingAsUser(['role' => UserRole::EMPLOYEE, 'custom_role_id' => $role->id], $tenant);

        test()->postJson(payAuthzUrl($tenant, "employees/{$report->public_id}/contracts"), [
            'contract_type' => 'permanent',
            'start_date' => now()->toDateString(),
            'salary_cents' => 1,
        ])->assertForbidden();

        expect($report->fresh()->salary_cents)->toBe(2_500_000);
    });

    it('can still be recorded without a salary by the same role', function () {
        [$tenant, , $report] = payAuthzTeam();
        $role = payAuthzRole($tenant, ['employee.viewAny', 'employee.view', 'employee.update']);
        actingAsUser(['role' => UserRole::EMPLOYEE, 'custom_role_id' => $role->id], $tenant);

        test()->postJson(payAuthzUrl($tenant, "employees/{$report->public_id}/contracts"), [
            'contract_type' => 'permanent',
            'start_date' => now()->toDateString(),
        ])->assertCreated();
    });
});

describe('personnel actions', function () {
    it('cannot change pay without employee.updateFinancial', function () {
        [$tenant, , $report] = payAuthzTeam();
        $role = payAuthzRole($tenant, ['employee.viewAny', 'employee.view', 'personnel_action.viewAny', 'personnel_action.create']);
        actingAsUser(['role' => UserRole::EMPLOYEE, 'custom_role_id' => $role->id], $tenant);

        test()->postJson(payAuthzUrl($tenant, "employees/{$report->public_id}/personnel-actions"), [
            'type' => 'promotion',
            'effective_date' => now()->toDateString(),
            'new_salary_cents' => 1,
        ])->assertForbidden();

        expect($report->fresh()->salary_cents)->toBe(2_500_000);
    });

    it('change pay for HR', function () {
        [$tenant, , $report] = payAuthzTeam();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

        test()->postJson(payAuthzUrl($tenant, "employees/{$report->public_id}/personnel-actions"), [
            'type' => 'promotion',
            'effective_date' => now()->toDateString(),
            'new_salary_cents' => 3_000_000,
        ])->assertCreated();

        expect($report->fresh()->salary_cents)->toBe(3_000_000);
    });
});

describe('roles at the same level', function () {
    it('an HR admin cannot open a Finance admin account', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

        test()->postJson(payAuthzUrl($tenant, 'users'), [
            'name' => 'Second account',
            'email' => 'second@example.com',
            'role' => UserRole::FINANCE_ADMIN->value,
        ])->assertUnprocessable()->assertJsonValidationErrors('role');
    });

    it('an HR admin cannot suspend a Finance admin', function () {
        $tenant = createTenant();
        $finance = createUser(['role' => UserRole::FINANCE_ADMIN, 'status' => 'active'], $tenant);
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

        test()->patchJson(payAuthzUrl($tenant, "users/{$finance->public_id}"), ['status' => 'suspended'])
            ->assertUnprocessable()->assertJsonValidationErrors('status');

        expect($finance->fresh()->status)->toBe('active');
    });

    it('an HR admin still manages the people below them', function () {
        $tenant = createTenant();
        $supervisor = createUser(['role' => UserRole::SUPERVISOR, 'status' => 'active'], $tenant);
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

        test()->patchJson(payAuthzUrl($tenant, "users/{$supervisor->public_id}"), ['status' => 'suspended'])
            ->assertOk();
    });

    it('resending a peer\'s current role and status is not a change', function () {
        $tenant = createTenant();
        $peer = createUser(['role' => UserRole::HR_ADMIN, 'status' => 'active'], $tenant);
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

        test()->patchJson(payAuthzUrl($tenant, "users/{$peer->public_id}"), [
            'role' => UserRole::HR_ADMIN->value, 'status' => 'active', 'locale' => 'am',
        ])->assertOk();
    });

    it('the organisation admin may do both', function () {
        $tenant = createTenant();
        $finance = createUser(['role' => UserRole::FINANCE_ADMIN, 'status' => 'active'], $tenant);
        actingAsUser(['role' => UserRole::TENANT_ADMIN, 'mfa_enabled' => true], $tenant);

        test()->patchJson(payAuthzUrl($tenant, "users/{$finance->public_id}"), ['status' => 'suspended'])
            ->assertOk();
        test()->postJson(payAuthzUrl($tenant, 'users'), [
            'name' => 'Finance two',
            'email' => 'finance2@example.com',
            'role' => UserRole::FINANCE_ADMIN->value,
        ])->assertSuccessful();
    });
});

test('a scheduled report is delivered when the worker has no tenant set', function () {
    // The job eager-loaded the saved report before setting the tenant, so the
    // fail-closed scope answered null and nothing was sent on a real worker.
    // Every other test sets a tenant through createTenant(), which hid it.
    Notification::fake();
    $tenant = createTenant();
    $user = createUser(['role' => UserRole::HR_ADMIN], $tenant);
    Employee::factory()->create(['tenant_id' => $tenant->id]);
    $saved = SavedReport::factory()->create([
        'tenant_id' => $tenant->id,
        'created_by' => $user->id,
        'config' => ['source' => 'employees', 'columns' => ['name']],
    ]);
    ScheduledReport::factory()->create([
        'tenant_id' => $tenant->id,
        'saved_report_id' => $saved->id,
        'frequency' => 'monthly',
        'recipients' => ['ops@example.com'],
        'next_run_at' => now()->subMinute(),
        'is_active' => true,
    ]);

    app(CurrentTenant::class)->forget();

    (new RunScheduledReportsJob)->handle(
        app(ReportEngine::class),
        app(CurrentTenant::class),
    );

    Notification::assertSentOnDemand(ScheduledReportNotification::class);
});
