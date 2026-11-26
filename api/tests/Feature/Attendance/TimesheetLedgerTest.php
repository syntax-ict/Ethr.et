<?php

declare(strict_types=1);

use App\Enums\AttendanceStatus;
use App\Enums\ExpectedDay;
use App\Enums\LeaveStatus;
use App\Enums\TimesheetCode;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\PayrollEntry;
use App\Models\PayrollRun;
use App\Models\Shift;
use App\Models\Tenant;
use App\Services\Attendance\TimesheetLedger;
use App\Services\CurrentTenant;
use App\Services\Payroll\PayrollEngine;
use App\Services\Payroll\PayrollPeriodLock;
use App\Services\Payroll\StatutoryRules;
use Carbon\Carbon;

/*
 * The monthly timesheet's ledger must say what payroll pays. These pin the two
 * to each other, and the cell codes to the rules that decide them.
 *
 * The fortnight used throughout: Monday 2026-10-12 to Sunday 2026-10-25, with
 * "now" on Friday 2026-10-23 at 10:00 in Addis Ababa, so yesterday is the 22nd.
 *
 *   Mon 12  present                       Mon 19  check-in only, no check-out
 *   Tue 13  late (09:10)                  Tue 20  nothing: an absence
 *   Wed 14  left early (16:00)            Wed 21  worked to 20:00: overtime
 *   Thu 15  public holiday                Thu 22  sick leave
 *   Fri 16  annual leave                  Fri 23  today, nothing yet: pending
 *   Sat 17  rest day, worked 3 hours      Sat 24, Sun 25  rest days
 */

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-23 10:00', 'Africa/Addis_Ababa'));
});

afterEach(fn () => Carbon::setTestNow());

const TL_FROM = '2026-10-12';
const TL_TO = '2026-10-25';

function tlAt(string $day, string $time): Carbon
{
    return Carbon::parse("$day $time", 'Africa/Addis_Ababa')->utc();
}

function tlRecord(Tenant $tenant, Employee $employee, Shift $shift, string $day, ?string $in, ?string $out, AttendanceStatus $status = AttendanceStatus::PRESENT): AttendanceRecord
{
    return AttendanceRecord::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'shift_id' => $shift->id,
        'date' => $day,
        'check_in' => $in === null ? null : tlAt($day, $in),
        'check_out' => $out === null ? null : tlAt($day, $out),
        'status' => $status,
    ]);
}

function tlLeave(Tenant $tenant, Employee $employee, LeaveType $type, string $start, string $end, float $days): LeaveRequest
{
    return LeaveRequest::factory()->approved()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $type->id,
        'start_date' => $start,
        'end_date' => $end,
        'days' => $days,
        'status' => LeaveStatus::APPROVED,
    ]);
}

/** @return array{tenant: Tenant, employee: Employee, shift: Shift} */
function tlFortnight(): array
{
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'hire_date' => '2025-01-01', 'salary_cents' => 1_000_000]);
    $shift = Shift::factory()->default()->create(['tenant_id' => $tenant->id]); // 08:30-17:30, 60 min break, grace 15

    Holiday::factory()->create(['tenant_id' => $tenant->id, 'date' => '2026-10-15', 'branch_id' => null, 'is_active' => true, 'recurring' => false]);

    tlRecord($tenant, $employee, $shift, '2026-10-12', '08:25', '17:35');
    tlRecord($tenant, $employee, $shift, '2026-10-13', '09:10', '17:30', AttendanceStatus::LATE);
    tlRecord($tenant, $employee, $shift, '2026-10-14', '08:30', '16:00', AttendanceStatus::EARLY_LEAVE);
    tlRecord($tenant, $employee, $shift, '2026-10-17', '09:00', '12:00');
    tlRecord($tenant, $employee, $shift, '2026-10-19', '08:30', null, AttendanceStatus::PENDING);
    tlRecord($tenant, $employee, $shift, '2026-10-21', '08:30', '20:00');

    $annual = LeaveType::factory()->create(['tenant_id' => $tenant->id, 'code' => 'annual']);
    $sick = LeaveType::factory()->sick()->create(['tenant_id' => $tenant->id, 'follows_art85' => true]);
    tlLeave($tenant, $employee, $annual, '2026-10-16', '2026-10-16', 1);
    tlLeave($tenant, $employee, $sick, '2026-10-22', '2026-10-22', 1);

    return compact('tenant', 'employee', 'shift');
}

function tlLedger(Tenant $tenant, $employees, bool $withPay = true): array
{
    $rules = app(StatutoryRules::class)->for($tenant, Carbon::parse(TL_FROM));

    return app(TimesheetLedger::class)->build(collect($employees), Carbon::parse(TL_FROM), Carbon::parse(TL_TO), $rules, $withPay);
}

function tlRow(array $ledger, Employee $employee): array
{
    return $ledger['employees'][$employee->id];
}

// ── The codes ──

it('reads each day as the rules that decide it do', function () {
    ['tenant' => $tenant, 'employee' => $employee] = tlFortnight();

    $cells = tlRow(tlLedger($tenant, [$employee], withPay: false), $employee)['cells'];
    $codes = array_map(fn (array $cell): ?string => $cell['code']?->short(), $cells);

    expect($codes)->toBe([
        '2026-10-12' => 'P',
        '2026-10-13' => 'L',
        '2026-10-14' => 'E',
        '2026-10-15' => 'H',
        '2026-10-16' => 'AL',
        '2026-10-17' => 'R',
        '2026-10-18' => 'R',
        '2026-10-19' => 'M',
        '2026-10-20' => 'A',
        '2026-10-21' => 'P',
        '2026-10-22' => 'SL',
        '2026-10-23' => null,
        '2026-10-24' => 'R',
        '2026-10-25' => 'R',
    ]);
});

it('counts a late day by its minutes and an early day by its own', function () {
    ['tenant' => $tenant, 'employee' => $employee] = tlFortnight();

    $row = tlRow(tlLedger($tenant, [$employee], withPay: false), $employee);

    expect($row['cells']['2026-10-13']['late_minutes'])->toBe(40)
        ->and($row['cells']['2026-10-14']['early_minutes'])->toBe(90)
        ->and($row['totals']['late_days'])->toBe(1)
        ->and($row['totals']['late_minutes'])->toBe(40)
        ->and($row['totals']['early_days'])->toBe(1)
        ->and($row['totals']['early_minutes'])->toBe(90);
});

it('does not rate a punch on a rest day late or early, and carries its overtime in the cell', function () {
    ['tenant' => $tenant, 'employee' => $employee] = tlFortnight();

    $saturday = tlRow(tlLedger($tenant, [$employee], withPay: false), $employee)['cells']['2026-10-17'];

    expect($saturday['code'])->toBe(TimesheetCode::REST_DAY)
        ->and($saturday['worked'])->toBeTrue()
        ->and($saturday['late_minutes'])->toBe(0)
        ->and($saturday['overtime'])->toBe(['rest_day' => 180]);
});

it('measures a shift\'s scheduled minutes the way worked minutes are measured', function () {
    ['tenant' => $tenant, 'employee' => $employee, 'shift' => $shift] = tlFortnight();

    $row = tlRow(tlLedger($tenant, [$employee], withPay: false), $employee);

    // 08:30-17:30 less the 60 minute break.
    expect($shift->scheduledMinutesOn('2026-10-12', 'Africa/Addis_Ababa'))->toBe(480)
        ->and($row['cells']['2026-10-12']['scheduled_minutes'])->toBe(480)
        ->and($row['cells']['2026-10-15']['scheduled_minutes'])->toBe(0) // a holiday schedules nothing
        ->and($row['cells']['2026-10-12']['worked_minutes'])->toBe(550 - 60);
});

it('names leave by the type\'s own fields, and a single part-day request as a half day', function () {
    expect(TimesheetCode::forLeave('leave', false, 'annual', false))->toBe(TimesheetCode::ANNUAL_LEAVE)
        ->and(TimesheetCode::forLeave('leave', true, 'sick', false))->toBe(TimesheetCode::SICK_LEAVE)
        ->and(TimesheetCode::forLeave('leave', false, 'maternity', false))->toBe(TimesheetCode::MATERNITY_LEAVE)
        ->and(TimesheetCode::forLeave('duty', false, 'official_duty', false))->toBe(TimesheetCode::FIELD_DUTY)
        ->and(TimesheetCode::forLeave('leave', false, 'unpaid', false))->toBe(TimesheetCode::OTHER_LEAVE)
        ->and(TimesheetCode::forLeave('leave', false, 'annual', true))->toBe(TimesheetCode::HALF_DAY_LEAVE);

    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'hire_date' => '2025-01-01']);
    Shift::factory()->default()->create(['tenant_id' => $tenant->id]);
    $annual = LeaveType::factory()->create(['tenant_id' => $tenant->id, 'code' => 'annual']);
    $duty = LeaveType::factory()->create(['tenant_id' => $tenant->id, 'code' => 'official_duty', 'kind' => 'duty']);
    tlLeave($tenant, $employee, $annual, '2026-10-13', '2026-10-13', 0.5);
    tlLeave($tenant, $employee, $duty, '2026-10-14', '2026-10-14', 1);

    $cells = tlRow(tlLedger($tenant, [$employee], withPay: false), $employee)['cells'];

    expect($cells['2026-10-13']['code'])->toBe(TimesheetCode::HALF_DAY_LEAVE)
        ->and($cells['2026-10-14']['code'])->toBe(TimesheetCode::FIELD_DUTY);
});

it('shows days before hire as not employed', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'hire_date' => '2026-10-14']);

    $cells = tlRow(tlLedger($tenant, [$employee], withPay: false), $employee)['cells'];

    expect($cells['2026-10-12']['code'])->toBe(TimesheetCode::NOT_EMPLOYED)
        ->and($cells['2026-10-12']['kind'])->toBe(ExpectedDay::NOT_EMPLOYED);
});

it('calls nobody absent in an organisation that recorded no attendance at all', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'hire_date' => '2025-01-01']);

    $row = tlRow(tlLedger($tenant, [$employee], withPay: false), $employee);

    expect($row['totals']['absent_days'])->toBe(0)
        ->and($row['totals']['pending_days'])->toBe($row['totals']['scheduled_days'])
        ->and($row['check_ok'])->toBeTrue();
});

// ── The row adds up ──

it('passes its own consistency check over a fortnight with leave, a holiday, a rest-day punch and a missing punch', function () {
    ['tenant' => $tenant, 'employee' => $employee] = tlFortnight();

    $totals = tlRow(tlLedger($tenant, [$employee], withPay: false), $employee)['totals'];

    expect($totals['scheduled_days'])->toBe(7)
        ->and($totals['present_days'])->toBe(5) // Mon, Tue, Wed, the open Monday, Wed 21
        ->and($totals['missing_days'])->toBe(1)
        ->and($totals['absent_days'])->toBe(1)
        ->and($totals['pending_days'])->toBe(1) // today
        ->and($totals['leave_days'])->toBe(2)
        ->and($totals['leave_by_code'])->toBe(['annual_leave' => 1, 'sick_leave' => 1])
        ->and($totals['holiday_days'])->toBe(1)
        ->and($totals['rest_days'])->toBe(4)
        ->and(tlRow(tlLedger($tenant, [$employee], withPay: false), $employee)['check_ok'])->toBeTrue();
});

// ── What payroll pays ──

it('totals the same overtime, unpaid leave and sick days that payroll reads, and the same absences', function () {
    ['tenant' => $tenant, 'employee' => $employee] = tlFortnight();
    $unpaid = LeaveType::factory()->create(['tenant_id' => $tenant->id, 'code' => 'unpaid', 'is_paid' => false]);
    tlLeave($tenant, $employee, $unpaid, '2026-10-20', '2026-10-20', 1);

    $from = Carbon::parse(TL_FROM);
    $to = Carbon::parse(TL_TO);
    $rules = app(StatutoryRules::class)->for($tenant, $from);
    $payroll = app(PayrollEngine::class);

    $figures = $payroll->attendanceFigures($employee, $from, $to, $rules, false);
    $totals = tlRow(tlLedger($tenant, [$employee]), $employee)['totals'];

    expect($totals['overtime'])->toBe($figures['overtime'])
        ->and($totals['unpaid_leave_days'])->toBe($figures['unpaid_leave_days'])
        ->and($totals['sick_half_days'])->toBe($figures['sick_half_days'])
        ->and($totals['sick_unpaid_days'])->toBe($figures['sick_unpaid_days'])
        ->and($totals['overtime']['rest_day'])->toBe(180)
        ->and($totals['overtime']['normal'])->toBe(155) // Wed 21 to 20:00 (150) and Mon 12 five minutes past 17:30
        ->and($totals['unpaid_leave_days'])->toBe(1.0);

    // Leave moved the 20th from absent to leave, in payroll's absence list and the ledger's alike.
    expect($payroll->unexplainedAbsencesFor(collect([$employee]), $from, $to)[$employee->id] ?? [])->toBe([]);
});

it('lists the same absent days as payroll\'s unexplained absences', function () {
    ['tenant' => $tenant, 'employee' => $employee] = tlFortnight();

    $from = Carbon::parse(TL_FROM);
    $to = Carbon::parse(TL_TO);
    $absentByPayroll = app(PayrollEngine::class)->unexplainedAbsencesFor(collect([$employee]), $from, $to)[$employee->id] ?? [];

    $row = tlRow(tlLedger($tenant, [$employee], withPay: false), $employee);
    $absentByLedger = array_keys(array_filter($row['cells'], fn (array $cell): bool => $cell['code'] === TimesheetCode::ABSENT));

    expect($absentByLedger)->toBe($absentByPayroll)->toBe(['2026-10-20']);
});

it('reads the same days as closed that the period lock refuses edits on', function () {
    ['tenant' => $tenant, 'employee' => $employee] = tlFortnight();

    $run = PayrollRun::factory()->create([
        'tenant_id' => $tenant->id,
        'period_start' => '2026-10-12',
        'period_end' => '2026-10-18',
        'status' => 'approved',
    ]);
    PayrollEntry::factory()->create(['tenant_id' => $tenant->id, 'payroll_run_id' => $run->id, 'employee_id' => $employee->id]);

    $row = tlRow(tlLedger($tenant, [$employee], withPay: false), $employee);
    $lock = app(PayrollPeriodLock::class);

    foreach ($row['cells'] as $day => $cell) {
        expect($cell['locked'])->toBe($lock->isClosed($employee->id, $day), $day);
    }
    expect($row['totals']['locked_days'])->toBe(7);
});

// ── Whose rows ──

it('shows only the employees it is given, and another organisation\'s rows never appear', function () {
    ['tenant' => $tenant, 'employee' => $employee] = tlFortnight();

    $other = Tenant::factory()->create();
    app(CurrentTenant::class)->set($other);
    $stranger = Employee::factory()->create(['tenant_id' => $other->id, 'hire_date' => '2025-01-01']);
    $otherShift = Shift::factory()->default()->create(['tenant_id' => $other->id]);
    tlRecord($other, $stranger, $otherShift, '2026-10-12', '08:30', '17:30');

    app(CurrentTenant::class)->set($tenant);
    $ledger = tlLedger($tenant, [$employee], withPay: false);

    expect(array_keys($ledger['employees']))->toBe([$employee->id])
        ->and($ledger['employees'][$employee->id]['cells']['2026-10-12']['records'])->toHaveCount(1);
});

// ── The days that are easy to get wrong ──

it('dates a night shift, and its overtime, to the day it started', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'hire_date' => '2025-01-01']);
    $night = Shift::factory()->default()->nightShift()->create(['tenant_id' => $tenant->id, 'working_days' => '1,2,3,4,5,6,7', 'break_minutes' => 0]);

    AttendanceRecord::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'shift_id' => $night->id,
        'date' => '2026-10-13',
        'check_in' => tlAt('2026-10-13', '21:58'),
        'check_out' => tlAt('2026-10-14', '06:30'),
        'status' => AttendanceStatus::PRESENT,
    ]);

    $cells = tlRow(tlLedger($tenant, [$employee], withPay: false), $employee)['cells'];

    expect($cells['2026-10-13']['code'])->toBe(TimesheetCode::PRESENT)
        ->and($cells['2026-10-13']['records'])->toHaveCount(1)
        ->and($cells['2026-10-14']['records'])->toBe([])
        ->and($cells['2026-10-13']['scheduled_minutes'])->toBe(480)
        ->and($cells['2026-10-13']['overtime'])->toBe(['normal' => 30]);
});

it('marks a day closed by auto check-out as still to fix until someone settles it', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'hire_date' => '2025-01-01']);
    $shift = Shift::factory()->default()->create(['tenant_id' => $tenant->id]);

    $record = tlRecord($tenant, $employee, $shift, '2026-10-13', '08:30', '17:30');
    $record->update(['metadata' => ['auto_checkout' => true]]);

    $cell = tlRow(tlLedger($tenant, [$employee], withPay: false), $employee)['cells']['2026-10-13'];

    expect($cell['code'])->toBe(TimesheetCode::MISSING_PUNCH)
        ->and($cell['exception'])->toBe(['type' => 'auto_checkout', 'state' => 'open']);
});

it('reads a period across Pagume and the Ethiopian new year without losing a day', function () {
    // Pagume 2018 E.C. runs 2026-09-06 to 2026-09-10; Meskerem 1, 2019 is 2026-09-11.
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'hire_date' => '2025-01-01']);
    $shift = Shift::factory()->default()->create(['tenant_id' => $tenant->id]);
    Holiday::factory()->create(['tenant_id' => $tenant->id, 'date' => '2026-09-11', 'name' => 'Enkutatash', 'branch_id' => null, 'is_active' => true, 'recurring' => false]);
    foreach (['2026-09-07', '2026-09-08', '2026-09-09', '2026-09-10', '2026-09-14'] as $day) {
        tlRecord($tenant, $employee, $shift, $day, '08:30', '17:30');
    }

    $rules = app(StatutoryRules::class)->for($tenant, Carbon::parse('2026-09-06'));
    $ledger = app(TimesheetLedger::class)->build(collect([$employee]), Carbon::parse('2026-09-06'), Carbon::parse('2026-09-15'), $rules, false);
    $row = $ledger['employees'][$employee->id];

    expect($ledger['days'])->toHaveCount(10)
        ->and($row['check_ok'])->toBeTrue()
        ->and($row['totals']['present_days'])->toBe(5)
        ->and($row['cells']['2026-09-11']['code'])->toBe(TimesheetCode::HOLIDAY)
        ->and($row['cells']['2026-09-10']['kind'])->toBe(ExpectedDay::WORKDAY);
});

// ── What the review found ──

it('counts the hours of a shift recorded twice once, and still lists both records', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'hire_date' => '2025-01-01']);
    $shift = Shift::factory()->default()->create(['tenant_id' => $tenant->id]);

    // A device punch and a phone punch of the same morning, neither voided.
    $first = tlRecord($tenant, $employee, $shift, '2026-10-13', '08:30', '18:00');
    $second = tlRecord($tenant, $employee, $shift, '2026-10-13', '08:31', '17:59');

    $row = tlRow(tlLedger($tenant, [$employee], withPay: false), $employee);
    $cell = $row['cells']['2026-10-13'];

    expect($cell['records'])->toEqualCanonicalizing([$first->public_id, $second->public_id])
        ->and($cell['worked_minutes'])->toBe(570 - 60) // the longer record: 08:30-18:00 less the break
        ->and($cell['overtime'])->toBe(['normal' => 30])
        ->and($row['totals']['present_days'])->toBe(1)
        ->and($row['check_ok'])->toBeTrue();
});

it('counts a late day that is also a missing punch as both, and as one present day', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'hire_date' => '2025-01-01']);
    $shift = Shift::factory()->default()->create(['tenant_id' => $tenant->id]);
    $record = tlRecord($tenant, $employee, $shift, '2026-10-13', '09:10', '17:30', AttendanceStatus::LATE);
    $record->update(['metadata' => ['auto_checkout' => true]]);

    $row = tlRow(tlLedger($tenant, [$employee], withPay: false), $employee);

    // One letter in the grid, two flags in the totals: late, early and missing
    // are reasons about a present day, not a partition of the days.
    expect($row['cells']['2026-10-13']['code'])->toBe(TimesheetCode::MISSING_PUNCH)
        ->and($row['totals']['present_days'])->toBe(1)
        ->and($row['totals']['missing_days'])->toBe(1)
        ->and($row['totals']['late_days'])->toBe(1)
        ->and($row['totals']['late_minutes'])->toBe(40);
});

it('shows the lateness payroll will deduct apart from the lateness observed', function () {
    ['tenant' => $tenant, 'employee' => $employee] = tlFortnight();

    // An approved lateness correction decided 25 of the 40 observed minutes.
    AttendanceRecord::query()->where('employee_id', $employee->id)->whereDate('date', '2026-10-13')
        ->firstOrFail()->update(['metadata' => ['pay_deduct_minutes' => 25, 'lateness_correction_id' => 'corr-1']]);

    $from = Carbon::parse(TL_FROM);
    $to = Carbon::parse(TL_TO);
    $rules = app(StatutoryRules::class)->for($tenant, $from);
    $totals = tlRow(tlLedger($tenant, [$employee], withPay: false), $employee)['totals'];

    expect($totals['late_minutes'])->toBe(40)
        ->and($totals['lateness_deduct_minutes'])->toBe(25)
        ->and($totals['lateness_corrections'])->toBe(['corr-1'])
        ->and($totals['lateness_deduct_minutes'])->toBe(
            app(PayrollEngine::class)->attendanceFigures($employee, $from, $to, $rules, true)['lateness_minutes'],
        );
});

it('fails its check when a total disagrees with the records, so a green row means something', function () {
    ['tenant' => $tenant, 'employee' => $employee] = tlFortnight();
    $row = tlRow(tlLedger($tenant, [$employee], withPay: false), $employee);
    expect($row['check_ok'])->toBeTrue();

    $consistent = new ReflectionMethod(TimesheetLedger::class, 'consistent');
    $kinds = [];
    foreach (array_keys($row['cells']) as $day) {
        $kinds[$day][$employee->id] = $row['cells'][$day]['kind'];
    }
    $recordsByDay = AttendanceRecord::query()->with('shift')->where('employee_id', $employee->id)->get()
        ->groupBy(fn (AttendanceRecord $r): string => $r->date->format('Y-m-d'));
    $tally = ['buckets' => $row['totals']['overtime']];
    $ledger = app(TimesheetLedger::class);

    $call = fn (array $totals) => $consistent->invoke($ledger, $row['cells'], $totals, $tally, $kinds, $employee->id, $recordsByDay, []);

    expect($call($row['totals']))->toBeTrue()
        ->and($call([...$row['totals'], 'present_days' => $row['totals']['present_days'] + 1]))->toBeFalse()
        ->and($call([...$row['totals'], 'worked_minutes' => $row['totals']['worked_minutes'] + 1]))->toBeFalse()
        ->and($call([...$row['totals'], 'scheduled_days' => $row['totals']['scheduled_days'] - 1]))->toBeFalse();
});

it('takes the leave that started first when two requests cover one day', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'hire_date' => '2025-01-01']);
    Shift::factory()->default()->create(['tenant_id' => $tenant->id]);
    $annual = LeaveType::factory()->create(['tenant_id' => $tenant->id, 'code' => 'annual']);
    $other = LeaveType::factory()->create(['tenant_id' => $tenant->id, 'code' => 'study']);
    tlLeave($tenant, $employee, $other, '2026-10-14', '2026-10-14', 0.5);
    tlLeave($tenant, $employee, $annual, '2026-10-13', '2026-10-15', 3);

    $cells = tlRow(tlLedger($tenant, [$employee], withPay: false), $employee)['cells'];

    expect($cells['2026-10-13']['code'])->toBe(TimesheetCode::ANNUAL_LEAVE)
        ->and($cells['2026-10-14']['code'])->toBe(TimesheetCode::ANNUAL_LEAVE);
});
