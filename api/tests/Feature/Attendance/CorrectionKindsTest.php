<?php

declare(strict_types=1);

use App\Enums\AttendanceStatus;
use App\Enums\CorrectionKind;
use App\Enums\CorrectionStatus;
use App\Enums\UserRole;
use App\Models\AttendanceCorrection;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSetting;
use App\Models\Employee;
use App\Models\PayrollEntry;
use App\Models\PayrollRun;
use App\Models\Shift;
use App\Models\Tenant;
use Carbon\Carbon;

// Attendance → payroll reconciliation, PR A (2026-10-27). One correction
// request, already on the approval engine, now carries the two things payroll
// staff reconciled by hand: a day the employee was at work with no record
// (missed_punch), and how many minutes of a late arrival payroll deducts
// (lateness). Lateness is opt-in with a stated basis, and approving it needs
// someone who processes payroll.

const KIND_DAY = '2026-06-15';

function kindsUrl(Tenant $tenant, string $path): string
{
    return "http://{$tenant->subdomain}.ethr.test/api/v1/{$path}";
}

function kindsAddis(string $time, string $day = KIND_DAY): Carbon
{
    return Carbon::parse("{$day} {$time}", 'Africa/Addis_Ababa')->utc();
}

/** A 08:30–17:30 shift with 15 minutes' grace, and an employee on it. */
function kindsEmployee(Tenant $tenant): array
{
    $shift = Shift::factory()->create([
        'tenant_id' => $tenant->id,
        'start_time' => '08:30',
        'end_time' => '17:30',
        'grace_minutes' => 15,
    ]);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);

    return [$employee, $shift];
}

/** A record 40 minutes late: in at 09:10 against an 08:30 start. */
function kindsLateRecord(Tenant $tenant, Employee $employee, Shift $shift): AttendanceRecord
{
    return AttendanceRecord::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'shift_id' => $shift->id,
        'date' => KIND_DAY,
        'status' => AttendanceStatus::LATE,
        'check_in' => kindsAddis('09:10'),
        'check_out' => kindsAddis('17:30'),
    ]);
}

function kindsEnableLateness(Tenant $tenant): void
{
    AttendanceSetting::updateOrCreate(['tenant_id' => $tenant->id], [
        ...AttendanceSetting::defaults(),
        'lateness_deduction_enabled' => true,
        'lateness_deduction_basis' => 'Work rules, clause 12',
    ]);
}

describe('a missed-punch claim', function () {
    beforeEach(fn () => Carbon::setTestNow(kindsAddis('10:00', '2026-06-20')));
    afterEach(fn () => Carbon::setTestNow());

    it('is filed for a day with no record, and approving it creates the record', function () {
        $tenant = createTenant();
        [$employee, $shift] = kindsEmployee($tenant);
        $employeeUser = createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant);
        test()->actingAs($employeeUser);

        $publicId = test()->postJson(kindsUrl($tenant, 'attendance/corrections'), [
            'kind' => 'missed_punch',
            'date' => KIND_DAY,
            'reason' => 'The reader at the gate was down.',
            'proposed_check_in' => kindsAddis('08:25')->toIso8601String(),
            'proposed_check_out' => kindsAddis('17:35')->toIso8601String(),
        ])->assertCreated()
            ->assertJsonPath('kind', 'missed_punch')
            ->assertJsonPath('date', KIND_DAY)
            ->assertJsonPath('attendance_record_public_id', null)
            ->json('public_id');

        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
        test()->putJson(kindsUrl($tenant, "attendance/corrections/{$publicId}/approve"))->assertOk();

        $correction = AttendanceCorrection::where('public_id', $publicId)->firstOrFail();
        $record = $correction->attendanceRecord;

        expect($correction->status)->toBe(CorrectionStatus::APPROVED)
            ->and($record)->not->toBeNull()
            ->and($record->date->format('Y-m-d'))->toBe(KIND_DAY)
            ->and($record->check_in->equalTo(kindsAddis('08:25')))->toBeTrue()
            ->and($record->check_out->equalTo(kindsAddis('17:35')))->toBeTrue()
            ->and($record->metadata['missed_punch'] ?? null)->toBeTrue()
            ->and($record->metadata['correction_id'] ?? null)->toBe($publicId);
    });

    it('is refused for a day that already has a record', function () {
        $tenant = createTenant();
        [$employee, $shift] = kindsEmployee($tenant);
        kindsLateRecord($tenant, $employee, $shift);
        test()->actingAs(createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant));

        test()->postJson(kindsUrl($tenant, 'attendance/corrections'), [
            'kind' => 'missed_punch',
            'date' => KIND_DAY,
            'reason' => 'x',
            'proposed_check_in' => kindsAddis('08:25')->toIso8601String(),
        ])->assertStatus(422)->assertJsonValidationErrors('date');
    });

    it('is refused for today or a day still ahead', function () {
        $tenant = createTenant();
        [$employee] = kindsEmployee($tenant);
        test()->actingAs(createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant));

        test()->postJson(kindsUrl($tenant, 'attendance/corrections'), [
            'kind' => 'missed_punch',
            'date' => '2026-06-20',
            'reason' => 'x',
            'proposed_check_in' => kindsAddis('08:25', '2026-06-20')->toIso8601String(),
        ])->assertStatus(422)->assertJsonValidationErrors('date');
    });

    it('cannot be filed by one employee for a colleague', function () {
        $tenant = createTenant();
        [$employee] = kindsEmployee($tenant);
        $colleague = Employee::factory()->create(['tenant_id' => $tenant->id]);
        test()->actingAs(createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant));

        test()->postJson(kindsUrl($tenant, 'attendance/corrections'), [
            'kind' => 'missed_punch',
            'employee_public_id' => $colleague->public_id,
            'date' => KIND_DAY,
            'reason' => 'x',
            'proposed_check_in' => kindsAddis('08:25')->toIso8601String(),
        ])->assertForbidden();
    });

    it('is refused at approval once the day is in an approved payroll run', function () {
        $tenant = createTenant();
        [$employee] = kindsEmployee($tenant);
        $correction = AttendanceCorrection::factory()->create([
            'tenant_id' => $tenant->id,
            'attendance_record_id' => null,
            'employee_id' => $employee->id,
            'kind' => CorrectionKind::MISSED_PUNCH,
            'date' => KIND_DAY,
            'proposed_check_in' => kindsAddis('08:25'),
        ]);
        $run = PayrollRun::factory()->create([
            'tenant_id' => $tenant->id, 'period_start' => '2026-06-01', 'period_end' => '2026-06-30', 'status' => 'approved',
        ]);
        PayrollEntry::factory()->create(['tenant_id' => $tenant->id, 'payroll_run_id' => $run->id, 'employee_id' => $employee->id]);
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

        test()->putJson(kindsUrl($tenant, "attendance/corrections/{$correction->public_id}/approve"))
            ->assertStatus(422)
            ->assertJsonPath('type', 'https://ethr.et/errors/payroll-period-closed');

        expect(AttendanceRecord::where('employee_id', $employee->id)->count())->toBe(0);
    });
});

describe('a lateness decision', function () {
    it('is refused while the organisation has not switched lateness deductions on', function () {
        $tenant = createTenant();
        [$employee, $shift] = kindsEmployee($tenant);
        $record = kindsLateRecord($tenant, $employee, $shift);
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

        test()->postJson(kindsUrl($tenant, 'attendance/corrections'), [
            'kind' => 'lateness',
            'attendance_record_public_id' => $record->public_id,
            'deduct_minutes' => 30,
            'reason' => 'Late without notice.',
        ])->assertStatus(422)->assertJsonValidationErrors('kind');
    });

    it('cannot deduct more minutes than the record missed', function () {
        $tenant = createTenant();
        kindsEnableLateness($tenant);
        [$employee, $shift] = kindsEmployee($tenant);
        $record = kindsLateRecord($tenant, $employee, $shift);
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

        test()->postJson(kindsUrl($tenant, 'attendance/corrections'), [
            'kind' => 'lateness',
            'attendance_record_public_id' => $record->public_id,
            'deduct_minutes' => 41,
            'reason' => 'x',
        ])->assertStatus(422)->assertJsonValidationErrors('deduct_minutes');
    });

    it('is refused for a record that was on time', function () {
        $tenant = createTenant();
        kindsEnableLateness($tenant);
        [$employee, $shift] = kindsEmployee($tenant);
        $record = kindsLateRecord($tenant, $employee, $shift);
        $record->update(['status' => AttendanceStatus::PRESENT]);
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

        test()->postJson(kindsUrl($tenant, 'attendance/corrections'), [
            'kind' => 'lateness',
            'attendance_record_public_id' => $record->public_id,
            'deduct_minutes' => 10,
            'reason' => 'x',
        ])->assertStatus(422)->assertJsonValidationErrors('attendance_record_public_id');
    });

    it('needs an approver who processes payroll, and moves no punch when approved', function () {
        $tenant = createTenant();
        kindsEnableLateness($tenant);
        [$employee, $shift] = kindsEmployee($tenant);
        $record = kindsLateRecord($tenant, $employee, $shift);
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

        $publicId = test()->postJson(kindsUrl($tenant, 'attendance/corrections'), [
            'kind' => 'lateness',
            'attendance_record_public_id' => $record->public_id,
            'deduct_minutes' => 40,
            'reason' => 'Late without notice.',
        ])->assertCreated()
            ->assertJsonPath('kind', 'lateness')
            ->assertJsonPath('deduct_minutes', 40)
            ->assertJsonPath('proposed_check_in', null)
            ->json('public_id');

        // HR approves corrections but does not process payroll.
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
        test()->putJson(kindsUrl($tenant, "attendance/corrections/{$publicId}/approve"))
            ->assertForbidden()
            ->assertJsonPath('type', 'https://ethr.et/errors/forbidden');

        actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);
        test()->putJson(kindsUrl($tenant, "attendance/corrections/{$publicId}/approve"))->assertOk();

        $record->refresh();
        expect($record->check_in->equalTo(kindsAddis('09:10')))->toBeTrue()
            ->and($record->status)->toBe(AttendanceStatus::LATE)
            ->and($record->metadata['pay_deduct_minutes'] ?? null)->toBe(40)
            ->and($record->metadata['lateness_correction_id'] ?? null)->toBe($publicId);
    });

    it('may still be rejected by an approver who does not process payroll', function () {
        $tenant = createTenant();
        kindsEnableLateness($tenant);
        [$employee, $shift] = kindsEmployee($tenant);
        $record = kindsLateRecord($tenant, $employee, $shift);
        $correction = AttendanceCorrection::factory()->create([
            'tenant_id' => $tenant->id,
            'attendance_record_id' => $record->id,
            'employee_id' => $employee->id,
            'kind' => CorrectionKind::LATENESS,
            'date' => KIND_DAY,
            'proposed_check_in' => null,
            'proposed_check_out' => null,
            'deduct_minutes' => 20,
        ]);
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

        test()->putJson(kindsUrl($tenant, "attendance/corrections/{$correction->public_id}/reject"), ['reason' => 'Excused'])
            ->assertOk();

        expect($record->fresh()->metadata['pay_deduct_minutes'] ?? null)->toBeNull();
    });
});

describe('the lateness switch', function () {
    it('needs a stated basis and the payroll configuration permission', function () {
        $tenant = createTenant();

        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
        test()->putJson(kindsUrl($tenant, 'attendance/settings'), [
            'lateness_deduction_enabled' => true,
            'lateness_deduction_basis' => 'Work rules, clause 12',
        ])->assertForbidden();

        actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);
        test()->putJson(kindsUrl($tenant, 'attendance/settings'), ['lateness_deduction_enabled' => true])
            ->assertStatus(422)
            ->assertJsonValidationErrors('lateness_deduction_basis');

        test()->putJson(kindsUrl($tenant, 'attendance/settings'), [
            'lateness_deduction_enabled' => true,
            'lateness_deduction_basis' => 'Work rules, clause 12',
        ])->assertOk()
            ->assertJsonPath('lateness_deduction_enabled', true)
            ->assertJsonPath('lateness_deduction_basis', 'Work rules, clause 12');
    });

    it('does not stop HR saving the rest of the page, which sends it unchanged', function () {
        $tenant = createTenant();
        kindsEnableLateness($tenant);
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

        test()->putJson(kindsUrl($tenant, 'attendance/settings'), [
            'qr_expiry_minutes' => 45,
            'lateness_deduction_enabled' => true,
            'lateness_deduction_basis' => 'Work rules, clause 12',
        ])->assertOk()->assertJsonPath('qr_expiry_minutes', 45);
    });

    it('is off for every organisation until switched on', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

        test()->getJson(kindsUrl($tenant, 'attendance/settings'))
            ->assertOk()
            ->assertJsonPath('lateness_deduction_enabled', false)
            ->assertJsonPath('lateness_deduction_basis', null);
    });
});
