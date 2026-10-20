<?php

declare(strict_types=1);

use App\Enums\AttendanceStatus;
use App\Enums\ConflictResolutionStatus;
use App\Enums\ConflictType;
use App\Enums\CorrectionStatus;
use App\Enums\UserRole;
use App\Models\AttendanceConflict;
use App\Models\AttendanceCorrection;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\PayrollEntry;
use App\Models\PayrollRun;
use App\Models\Tenant;
use Carbon\Carbon;

// Blueprint assessment 2026-10-20, attendance acceptance test: changing an
// approved attendance record must not invisibly change a payroll result that
// has already been approved. A paid day is closed to human edits until the
// run is voided.

const LOCK_DAY = '2026-06-15';
const LOCK_CLOSED = 'https://ethr.et/errors/payroll-period-closed';

/** An employee with a record on 15 June, paid in an approved June run. */
function lockFixture(string $runStatus = 'approved'): array
{
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'employee_code' => 'E-LOCK']);
    $record = AttendanceRecord::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'date' => LOCK_DAY,
        'status' => AttendanceStatus::PRESENT,
        'check_in' => Carbon::parse(LOCK_DAY.' 08:30', 'Africa/Addis_Ababa')->utc(),
        'check_out' => Carbon::parse(LOCK_DAY.' 17:30', 'Africa/Addis_Ababa')->utc(),
    ]);
    $run = PayrollRun::factory()->create([
        'tenant_id' => $tenant->id,
        'period_start' => '2026-06-01',
        'period_end' => '2026-06-30',
        'status' => $runStatus,
    ]);
    PayrollEntry::factory()->create([
        'tenant_id' => $tenant->id, 'payroll_run_id' => $run->id, 'employee_id' => $employee->id,
    ]);

    return [$tenant, $employee, $record, $run];
}

function lockCorrection(Tenant $tenant, AttendanceRecord $record): AttendanceCorrection
{
    return AttendanceCorrection::factory()->create([
        'tenant_id' => $tenant->id,
        'attendance_record_id' => $record->id,
        'employee_id' => $record->employee_id,
        'proposed_check_in' => Carbon::parse(LOCK_DAY.' 07:00', 'Africa/Addis_Ababa')->utc(),
        'proposed_check_out' => null,
        'status' => CorrectionStatus::PENDING,
    ]);
}

function lockUrl(Tenant $tenant, string $path): string
{
    return "http://{$tenant->subdomain}.ethr.test/api/v1/{$path}";
}

describe('a correction on a paid day', function () {
    it('is refused at approval, and the punch is unchanged', function () {
        [$tenant, , $record] = lockFixture();
        $correction = lockCorrection($tenant, $record);
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

        test()->putJson(lockUrl($tenant, "attendance/corrections/{$correction->public_id}/approve"))
            ->assertStatus(422)
            ->assertJsonPath('type', LOCK_CLOSED);

        expect($correction->fresh()->status)->toBe(CorrectionStatus::PENDING);
        expect($record->fresh()->check_in->equalTo($record->check_in))->toBeTrue();
    });

    it('is refused in a batch approval too', function () {
        [$tenant, , $record] = lockFixture();
        $correction = lockCorrection($tenant, $record);
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

        test()->postJson(lockUrl($tenant, 'approvals/batch'), [
            'actions' => [['type' => 'correction', 'public_id' => $correction->public_id, 'action' => 'approve']],
        ])->assertOk();

        expect($correction->fresh()->status)->toBe(CorrectionStatus::PENDING);
    });

    it('cannot be filed', function () {
        [$tenant, $employee, $record] = lockFixture();
        actingAsUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant);

        test()->postJson(lockUrl($tenant, 'attendance/corrections'), [
            'attendance_record_public_id' => $record->public_id,
            'reason' => 'I arrived earlier',
            'proposed_check_in' => Carbon::parse(LOCK_DAY.' 07:00', 'Africa/Addis_Ababa')->toIso8601String(),
        ])->assertStatus(422)->assertJsonPath('type', LOCK_CLOSED);
    });

    it('says so in its payroll impact', function () {
        [$tenant, , $record] = lockFixture();
        $correction = lockCorrection($tenant, $record);
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

        test()->getJson(lockUrl($tenant, "attendance/corrections/{$correction->public_id}/payroll-impact"))
            ->assertOk()
            ->assertJsonPath('in_open_payroll_period', false);
    });

    it('is approved once the run is voided', function () {
        [$tenant, , $record] = lockFixture('voided');
        $correction = lockCorrection($tenant, $record);
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

        test()->putJson(lockUrl($tenant, "attendance/corrections/{$correction->public_id}/approve"))->assertOk();
        test()->getJson(lockUrl($tenant, "attendance/corrections/{$correction->public_id}/payroll-impact"))
            ->assertJsonPath('in_open_payroll_period', true);
    });

    it('is approved for an employee the approved run did not pay', function () {
        [$tenant] = lockFixture();
        $other = Employee::factory()->create(['tenant_id' => $tenant->id]);
        $record = AttendanceRecord::factory()->create([
            'tenant_id' => $tenant->id, 'employee_id' => $other->id, 'date' => LOCK_DAY,
            'check_in' => Carbon::parse(LOCK_DAY.' 08:30', 'Africa/Addis_Ababa')->utc(),
        ]);
        $correction = lockCorrection($tenant, $record);
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

        test()->putJson(lockUrl($tenant, "attendance/corrections/{$correction->public_id}/approve"))->assertOk();
    });
});

it('refuses a manual entry on a paid day', function () {
    [$tenant, $employee] = lockFixture();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    test()->postJson(lockUrl($tenant, 'attendance/manual'), [
        'idempotency_key' => 'manual-lock-1',
        'employee_public_id' => $employee->public_id,
        'date' => LOCK_DAY,
        'check_in' => '08:00',
        'check_out' => '17:00',
        'reason' => 'Forgot to punch',
    ])->assertStatus(422)->assertJsonPath('type', LOCK_CLOSED);
});

it('refuses to void either record of a paid day when resolving a conflict', function () {
    [$tenant, $employee, $record] = lockFixture();
    $twin = AttendanceRecord::factory()->create([
        'tenant_id' => $tenant->id, 'employee_id' => $employee->id, 'date' => LOCK_DAY,
        'check_in' => Carbon::parse(LOCK_DAY.' 08:31', 'Africa/Addis_Ababa')->utc(),
    ]);
    $conflict = AttendanceConflict::create([
        'tenant_id' => $tenant->id, 'employee_id' => $employee->id,
        'record_a_id' => $record->id, 'record_b_id' => $twin->id,
        'conflict_type' => ConflictType::TIME_OVERLAP, 'resolution' => ConflictResolutionStatus::PENDING,
    ]);
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    test()->putJson(lockUrl($tenant, "attendance/conflicts/{$conflict->public_id}/resolve"), ['resolution' => 'keep_a'])
        ->assertStatus(422)
        ->assertJsonPath('type', LOCK_CLOSED);

    expect($twin->fresh()->status)->not->toBe(AttendanceStatus::VOIDED);
});

it('leaves a paid day out of an import and says which row', function () {
    [$tenant] = lockFixture();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    $result = test()->postJson(lockUrl($tenant, 'attendance/import/commit'), [
        'import_key' => 'lock-import',
        'rows' => [
            ['employee_code' => 'E-LOCK', 'date' => LOCK_DAY, 'check_in' => '08:00', 'check_out' => '17:00'],
            ['employee_code' => 'E-LOCK', 'date' => '2026-07-01', 'check_in' => '08:00', 'check_out' => '17:00'],
        ],
    ])->assertCreated()->json();

    expect($result['created'])->toBe(1);
    expect($result['errors'])->toHaveCount(1);
    expect(AttendanceRecord::whereDate('date', LOCK_DAY)->count())->toBe(1);
});
