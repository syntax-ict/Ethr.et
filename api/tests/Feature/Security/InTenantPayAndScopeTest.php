<?php

declare(strict_types=1);

use App\Enums\PersonnelActionType;
use App\Enums\UserRole;
use App\Jobs\RunScheduledReportsJob;
use App\Models\AuditLog;
use App\Models\CustomRole;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\PayrollEntry;
use App\Models\PayrollRun;
use App\Models\Permission;
use App\Models\PersonnelAction;
use App\Models\SavedReport;
use App\Models\ScheduledReport;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\ScheduledReportNotification;
use App\Services\CurrentTenant;
use App\Services\Report\ReportEngine;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;

// Blueprint assessment 2026-10-20, defects D4-D7: pay data and other people's
// records inside one organisation, shown to callers who were never meant to
// see them. None crossed a tenant; every one crossed a permission or a scope.

function inTenantUrl(Tenant $tenant, string $path): string
{
    return "http://{$tenant->subdomain}.ethr.test/api/v1/{$path}";
}

/** A supervisor with one direct report and one employee outside the team. */
function inTenantTeam(): array
{
    $tenant = createTenant();
    $lead = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $report = Employee::factory()->create([
        'tenant_id' => $tenant->id, 'supervisor_id' => $lead->id, 'salary_cents' => 2_500_000,
    ]);
    $outsider = Employee::factory()->create(['tenant_id' => $tenant->id, 'salary_cents' => 9_000_000]);

    return [$tenant, $lead, $report, $outsider];
}

function inTenantCustomRole(Tenant $tenant, array $permissions, string $scope = 'all'): CustomRole
{
    $role = CustomRole::create(['tenant_id' => $tenant->id, 'name' => 'Custom '.count($permissions), 'org_scope' => $scope]);
    $role->permissions()->sync(Permission::whereIn('name', $permissions)->pluck('id'));

    return $role;
}

describe('D4: salary on the employee record', function () {
    it('is not shown to a supervisor', function () {
        [$tenant, $lead, $report] = inTenantTeam();
        actingAsUser(['role' => UserRole::SUPERVISOR, 'employee_id' => $lead->id], $tenant);

        test()->getJson(inTenantUrl($tenant, "employees/{$report->public_id}"))
            ->assertOk()
            ->assertJsonMissingPath('salary_cents');
    });

    it('is shown to HR, who hold employee.viewFinancial', function () {
        [$tenant, , $report] = inTenantTeam();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

        test()->getJson(inTenantUrl($tenant, "employees/{$report->public_id}"))
            ->assertOk()
            ->assertJsonPath('salary_cents', 2_500_000);
    });

    it('cannot be changed with employee.update alone', function () {
        [$tenant, , $report] = inTenantTeam();
        $role = inTenantCustomRole($tenant, ['employee.viewAny', 'employee.view', 'employee.update']);
        actingAsUser(['role' => UserRole::EMPLOYEE, 'custom_role_id' => $role->id], $tenant);

        test()->putJson(inTenantUrl($tenant, "employees/{$report->public_id}"), ['salary_cents' => 1])
            ->assertForbidden();
        expect($report->fresh()->salary_cents)->toBe(2_500_000);

        // Resending the value already stored is not a change, so the rest of
        // the form still saves.
        test()->putJson(inTenantUrl($tenant, "employees/{$report->public_id}"), [
            'salary_cents' => 2_500_000, 'name' => 'Renamed',
        ])->assertOk();
        expect($report->fresh()->name)->toBe('Renamed');
    });

    it('is changed by HR', function () {
        [$tenant, , $report] = inTenantTeam();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

        test()->putJson(inTenantUrl($tenant, "employees/{$report->public_id}"), ['salary_cents' => 3_000_000])
            ->assertOk();
        expect($report->fresh()->salary_cents)->toBe(3_000_000);
    });
});

describe('D5: personnel history', function () {
    function inTenantPromotion(Tenant $tenant, Employee $employee): void
    {
        PersonnelAction::create([
            'tenant_id' => $tenant->id,
            'employee_id' => $employee->id,
            'type' => PersonnelActionType::PROMOTION,
            'effective_date' => '2026-09-01',
            'changes' => [
                'salary' => ['from' => 2_000_000, 'to' => 2_500_000],
                'position' => ['from' => 'Officer', 'to' => 'Senior Officer'],
            ],
        ]);
    }

    it('is not readable for an employee outside the supervisor\'s team', function () {
        [$tenant, $lead, , $outsider] = inTenantTeam();
        inTenantPromotion($tenant, $outsider);
        actingAsUser(['role' => UserRole::SUPERVISOR, 'employee_id' => $lead->id], $tenant);

        test()->getJson(inTenantUrl($tenant, "employees/{$outsider->public_id}/personnel-actions"))
            ->assertNotFound();
    });

    it('shows a supervisor their report\'s history without the salary change', function () {
        [$tenant, $lead, $report] = inTenantTeam();
        inTenantPromotion($tenant, $report);
        actingAsUser(['role' => UserRole::SUPERVISOR, 'employee_id' => $lead->id], $tenant);

        test()->getJson(inTenantUrl($tenant, "employees/{$report->public_id}/personnel-actions"))
            ->assertOk()
            ->assertJsonPath('0.changes.position.to', 'Senior Officer')
            ->assertJsonMissingPath('0.changes.salary');
    });

    it('shows HR the salary change', function () {
        [$tenant, , $report] = inTenantTeam();
        inTenantPromotion($tenant, $report);
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

        test()->getJson(inTenantUrl($tenant, "employees/{$report->public_id}/personnel-actions"))
            ->assertOk()
            ->assertJsonPath('0.changes.salary.to', 2_500_000);
    });
});

describe('D6: reports', function () {
    function inTenantPayrollEntry(Tenant $tenant, Employee $employee): void
    {
        $run = PayrollRun::factory()->create(['tenant_id' => $tenant->id, 'status' => 'approved']);
        PayrollEntry::factory()->create([
            'tenant_id' => $tenant->id, 'payroll_run_id' => $run->id,
            'employee_id' => $employee->id, 'net_cents' => 1_234_500,
        ]);
    }

    it('refuses the payroll source to HR, who were denied payroll.viewAll', function () {
        [$tenant, , $report] = inTenantTeam();
        inTenantPayrollEntry($tenant, $report);
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

        test()->postJson(inTenantUrl($tenant, 'reports/generate'), ['source' => 'payroll'])
            ->assertForbidden();
        test()->postJson(inTenantUrl($tenant, 'reports/export'), ['source' => 'payroll'])
            ->assertForbidden();
    });

    it('gives the payroll source to finance', function () {
        [$tenant, , $report] = inTenantTeam();
        inTenantPayrollEntry($tenant, $report);
        actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);

        test()->postJson(inTenantUrl($tenant, 'reports/generate'), ['source' => 'payroll'])
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.net_cents', 1_234_500);
    });

    it('limits rows to the caller\'s scope and drops salary without employee.viewFinancial', function () {
        [$tenant, $lead, $report, $outsider] = inTenantTeam();
        $role = inTenantCustomRole($tenant, ['report.generate'], 'direct_reports');
        actingAsUser(['role' => UserRole::SUPERVISOR, 'employee_id' => $lead->id, 'custom_role_id' => $role->id], $tenant);

        $rows = test()->postJson(inTenantUrl($tenant, 'reports/generate'), ['source' => 'employees'])
            ->assertOk()
            ->json('data');

        $names = array_column($rows, 'name');
        expect($names)->toContain($report->name)
            ->and($names)->not->toContain($outsider->name);
        foreach ($rows as $row) {
            expect($row)->not->toHaveKey('salary_cents');
        }
    });
});

describe('D6: scheduled reports', function () {
    function inTenantSchedule(Tenant $tenant, User $creator, string $source, array $recipients): ScheduledReport
    {
        $saved = SavedReport::factory()->create([
            'tenant_id' => $tenant->id, 'created_by' => $creator->id,
            'name' => 'Pay', 'config' => ['source' => $source],
        ]);

        return ScheduledReport::factory()->create([
            'tenant_id' => $tenant->id, 'saved_report_id' => $saved->id, 'frequency' => 'monthly',
            'recipients' => $recipients, 'next_run_at' => now()->subMinute(), 'is_active' => true,
        ]);
    }

    function inTenantRunSchedules(): void
    {
        (new RunScheduledReportsJob)->handle(app(ReportEngine::class), app(CurrentTenant::class));
    }

    it('sends pay figures only to people in the organisation who may read them', function () {
        Notification::fake();
        [$tenant, , $report] = inTenantTeam();
        inTenantPayrollEntry($tenant, $report);
        $finance = actingAsUser(['role' => UserRole::FINANCE_ADMIN, 'email' => 'finance@example.test'], $tenant);
        inTenantSchedule($tenant, $finance, 'payroll', ['finance@example.test', 'outside@example.com']);

        inTenantRunSchedules();

        // One delivery, and it is finance's: the outside address got nothing.
        Notification::assertSentOnDemandTimes(ScheduledReportNotification::class, 1);
        Notification::assertSentOnDemand(ScheduledReportNotification::class, fn ($n, $c, $notifiable) => $notifiable->routes['mail'] === 'finance@example.test');
    });

    it('stops when the person who saved it can no longer run it', function () {
        Notification::fake();
        [$tenant, , $report] = inTenantTeam();
        inTenantPayrollEntry($tenant, $report);
        $hr = actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
        $schedule = inTenantSchedule($tenant, $hr, 'payroll', ['hr@example.test']);

        inTenantRunSchedules();

        Notification::assertNotSentTo(new AnonymousNotifiable, ScheduledReportNotification::class);
        expect($schedule->fresh()->last_error)->not->toBeNull();
    });
});

describe('D7: the shift schedule', function () {
    it('lists only the assignments of employees the caller reaches', function () {
        [$tenant, , $report, $outsider] = inTenantTeam();
        $shift = Shift::factory()->create(['tenant_id' => $tenant->id]);
        foreach ([$report, $outsider] as $employee) {
            ShiftAssignment::create([
                'tenant_id' => $tenant->id, 'shift_id' => $shift->id,
                'assignable_type' => Employee::class, 'assignable_id' => $employee->id,
                'effective_from' => '2026-01-01',
            ]);
        }

        actingAsUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $report->id], $tenant);
        expect(test()->getJson(inTenantUrl($tenant, 'shifts/schedule'))->assertOk()->json('meta.total'))->toBe(1);

        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
        expect(test()->getJson(inTenantUrl($tenant, 'shifts/schedule'))->assertOk()->json('meta.total'))->toBe(2);
    });
});

describe('a sensitive employee document', function () {
    function inTenantDocument(Tenant $tenant, Employee $employee, string $type): EmployeeDocument
    {
        return EmployeeDocument::create([
            'tenant_id' => $tenant->id, 'employee_id' => $employee->id, 'type' => $type,
            'title' => ucfirst($type), 'file_path' => "employees/{$employee->public_id}/documents/{$type}.pdf",
            'file_size' => 100, 'mime_type' => 'application/pdf',
        ]);
    }

    it('is hidden from a supervisor', function () {
        [$tenant, $lead, $report] = inTenantTeam();
        $medical = inTenantDocument($tenant, $report, 'medical');
        inTenantDocument($tenant, $report, 'contract');
        actingAsUser(['role' => UserRole::SUPERVISOR, 'employee_id' => $lead->id], $tenant);

        $types = array_column(
            test()->getJson(inTenantUrl($tenant, "employees/{$report->public_id}/documents"))->assertOk()->json(),
            'type',
        );
        expect($types)->toBe(['contract']);

        test()->getJson(inTenantUrl($tenant, "employees/{$report->public_id}/documents/{$medical->public_id}"))
            ->assertNotFound();
    });

    it('is shown to HR, and opening it is recorded', function () {
        [$tenant, , $report] = inTenantTeam();
        $medical = inTenantDocument($tenant, $report, 'medical');
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

        test()->getJson(inTenantUrl($tenant, "employees/{$report->public_id}/documents/{$medical->public_id}"))
            ->assertOk();

        expect(AuditLog::where('action', 'employee.document.viewed')->count())->toBe(1);
    });
});
