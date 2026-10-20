<?php

declare(strict_types=1);

use App\Enums\AttendanceSource;
use App\Enums\AttendanceStatus;
use App\Enums\UserRole;
use App\Models\AttendanceRecord;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\PayrollEntry;
use App\Models\PayrollRun;
use App\Models\Shift;
use App\Services\Attendance\AttendanceEngine;
use App\Services\Attendance\AttendanceInput;
use App\Services\Payroll\PayrollEngine;
use Carbon\Carbon;

// Blueprint assessment 2026-10-20, defects D1 and D2, and the payroll
// maker-checker it called for. Each test fails against the code before the fix.

afterEach(function () {
    Carbon::setTestNow();
});

describe('a voided duplicate punch', function () {
    // ConflictResolver voids the loser of a merged duplicate and leaves its
    // punches in place. The overtime query had no status predicate, so the
    // same shift's overtime was paid once per copy.
    it('pays no overtime', function () {
        $tenant = createTenant();
        $user = actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);

        $employee = Employee::factory()->create([
            'tenant_id' => $tenant->id,
            'salary_cents' => 500000,
            'hire_date' => '2020-01-01',
        ]);
        $shift = Shift::factory()->create(['tenant_id' => $tenant->id]); // 08:30-17:30

        foreach ([AttendanceStatus::PRESENT, AttendanceStatus::VOIDED] as $status) {
            AttendanceRecord::factory()->create([
                'tenant_id' => $tenant->id,
                'employee_id' => $employee->id,
                'shift_id' => $shift->id,
                'date' => '2026-06-15',
                'status' => $status,
                'check_in' => Carbon::parse('2026-06-15 08:30', 'Africa/Addis_Ababa')->utc(),
                'check_out' => Carbon::parse('2026-06-15 19:30', 'Africa/Addis_Ababa')->utc(),
            ]);
        }

        $run = app(PayrollEngine::class)->process(
            $tenant->id,
            Carbon::parse('2026-06-01'),
            Carbon::parse('2026-06-30'),
            $user->id,
        )->run;

        $entry = PayrollEntry::where('payroll_run_id', $run->id)->firstOrFail();
        $step = collect($entry->calculation_log['steps'])->firstWhere('step', 'overtime');

        expect($step['by_type']['normal']['minutes'])->toBe(120);
    });

    it('is never the record a check-out closes', function () {
        Carbon::setTestNow('2026-07-31 14:00:00'); // 17:00 in Addis Ababa

        $tenant = createTenant();
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);

        $live = AttendanceRecord::factory()->create([
            'tenant_id' => $tenant->id,
            'employee_id' => $employee->id,
            'date' => '2026-07-31',
            'status' => AttendanceStatus::PRESENT,
            'check_in' => Carbon::parse('2026-07-31 08:00', 'Africa/Addis_Ababa')->utc(),
            'check_out' => null,
        ]);
        // The later check-in, so `latest('check_in')` reached it first.
        $voided = AttendanceRecord::factory()->create([
            'tenant_id' => $tenant->id,
            'employee_id' => $employee->id,
            'date' => '2026-07-31',
            'status' => AttendanceStatus::VOIDED,
            'check_in' => Carbon::parse('2026-07-31 08:05', 'Africa/Addis_Ababa')->utc(),
            'check_out' => null,
        ]);

        app(AttendanceEngine::class)->record(new AttendanceInput(
            employeeId: $employee->id,
            tenantId: $tenant->id,
            source: AttendanceSource::WEB,
            type: 'check_out',
            idempotencyKey: 'close-live',
        ));

        expect($live->fresh()->check_out)->not->toBeNull();
        expect($voided->fresh()->check_out)->toBeNull();
    });
});

describe('the bank file', function () {
    it('is refused for a run that is not approved', function (string $status, string $path) {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);
        $run = PayrollRun::factory()->create(['tenant_id' => $tenant->id, 'status' => $status]);

        test()->getJson("/api/v1/payroll/runs/{$run->public_id}/{$path}")
            ->assertStatus(422)
            ->assertJsonPath('type', 'https://ethr.et/errors/invalid-state');

        expect(AuditLog::where('action', 'payroll.bank_export')->count())->toBe(0);
    })->with([
        'draft' => 'draft',
        'completed' => 'completed',
        'voided' => 'voided',
    ])->with([
        'json' => 'export/bank',
        'csv' => 'export/bank-csv',
    ]);

    it('is built for an approved run', function (string $path) {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);
        $run = PayrollRun::factory()->create(['tenant_id' => $tenant->id, 'status' => 'approved']);

        test()->get("/api/v1/payroll/runs/{$run->public_id}/{$path}")->assertOk();
    })->with(['export/bank', 'export/bank-csv']);
});

describe('payroll approval', function () {
    it('refuses the person who processed the run while someone else can approve', function () {
        $tenant = createTenant();
        $maker = actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);
        createUser(['role' => UserRole::TENANT_ADMIN], $tenant);

        $run = PayrollRun::factory()->create([
            'tenant_id' => $tenant->id,
            'status' => 'completed',
            'processed_by' => $maker->id,
        ]);

        test()->putJson("/api/v1/payroll/runs/{$run->public_id}/approve")
            ->assertStatus(422)
            ->assertJsonPath('type', 'https://ethr.et/errors/payroll-self-approval');

        expect($run->fresh()->status)->toBe('completed');
    });

    it('lets a second approver approve', function () {
        $tenant = createTenant();
        $maker = createUser(['role' => UserRole::FINANCE_ADMIN], $tenant);
        actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

        $run = PayrollRun::factory()->create([
            'tenant_id' => $tenant->id,
            'status' => 'completed',
            'processed_by' => $maker->id,
        ]);

        test()->putJson("/api/v1/payroll/runs/{$run->public_id}/approve")
            ->assertOk()
            ->assertJsonPath('status', 'approved');

        $audit = AuditLog::where('action', 'payroll.approved')->firstOrFail();
        expect($audit->payload['self_approved'] ?? null)->toBeNull();
    });

    // An organisation with one administrator must still be able to pay its
    // staff, so the approval goes through and says it was the maker's own.
    it('lets a sole approver approve their own run and records it', function () {
        $tenant = createTenant();
        $maker = actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);
        // An inactive admin and an employee do not count as a second approver.
        createUser(['role' => UserRole::TENANT_ADMIN, 'status' => 'inactive'], $tenant);
        createUser(['role' => UserRole::EMPLOYEE], $tenant);

        $run = PayrollRun::factory()->create([
            'tenant_id' => $tenant->id,
            'status' => 'completed',
            'processed_by' => $maker->id,
        ]);

        test()->putJson("/api/v1/payroll/runs/{$run->public_id}/approve")
            ->assertOk()
            ->assertJsonPath('status', 'approved');

        $audit = AuditLog::where('action', 'payroll.approved')->firstOrFail();
        expect($audit->payload['self_approved'] ?? null)->toBeTrue();
    });

    it('does not count an approver in another organisation', function () {
        $tenant = createTenant();
        $maker = actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);
        createUser(['role' => UserRole::TENANT_ADMIN], createTenant());

        $run = PayrollRun::factory()->create([
            'tenant_id' => $tenant->id,
            'status' => 'completed',
            'processed_by' => $maker->id,
        ]);

        test()->putJson("http://{$tenant->subdomain}.ethr.test/api/v1/payroll/runs/{$run->public_id}/approve")->assertOk();
    });
});
