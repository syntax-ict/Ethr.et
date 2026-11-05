<?php

declare(strict_types=1);

use App\Enums\AttendanceSource;
use App\Exceptions\NoOpenCheckIn;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSetting;
use App\Models\Employee;
use App\Models\Tenant;
use App\Services\Attendance\AttendanceEngine;
use App\Services\Attendance\AttendanceInput;
use App\Services\CurrentTenant;
use Carbon\Carbon;

// A-Z review D-05 (2026-11-04). A check-out that reaches the server late (an
// offline phone syncing, a device backlog) closed the latest open record of
// the day, even one that began after the check-out happened, and that record
// ended before it started. `auto` already refused this; an explicit check-out
// did not.

function lateOutAddis(string $time): Carbon
{
    return Carbon::parse("2026-10-28 {$time}", 'Africa/Addis_Ababa')->utc();
}

function lateOutOpen(Tenant $tenant, Employee $employee, string $time): AttendanceRecord
{
    return AttendanceRecord::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'date' => '2026-10-28',
        'check_in' => lateOutAddis($time),
        'check_out' => null,
    ]);
}

function lateOutPunch(Tenant $tenant, Employee $employee, string $time): void
{
    app(AttendanceEngine::class)->record(new AttendanceInput(
        employeeId: $employee->id,
        tenantId: $tenant->id,
        source: AttendanceSource::MOBILE,
        type: 'check_out',
        idempotencyKey: 'late-out-'.uniqid(),
        occurredAt: lateOutAddis($time)->toIso8601String(),
    ));
}

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-28 18:00:00', 'Africa/Addis_Ababa'));
    $this->tenant = createTenant(['timezone' => 'Africa/Addis_Ababa']);
    AttendanceSetting::create([...AttendanceSetting::defaults(), 'tenant_id' => $this->tenant->id]);
    app(CurrentTenant::class)->set($this->tenant);
    $this->employee = Employee::factory()->create(['tenant_id' => $this->tenant->id]);
});

afterEach(fn () => Carbon::setTestNow());

it('closes the open record that began before the check-out, not a later one', function () {
    $morning = lateOutOpen($this->tenant, $this->employee, '08:00');
    $afternoon = lateOutOpen($this->tenant, $this->employee, '13:00');

    lateOutPunch($this->tenant, $this->employee, '12:00');

    expect($morning->fresh()->check_out?->equalTo(lateOutAddis('12:00')))->toBeTrue()
        ->and($afternoon->fresh()->check_out)->toBeNull();
});

it('closes nothing with a check-out made before every open record began', function () {
    $afternoon = lateOutOpen($this->tenant, $this->employee, '13:00');

    expect(fn () => lateOutPunch($this->tenant, $this->employee, '12:00'))->toThrow(NoOpenCheckIn::class)
        ->and($afternoon->fresh()->check_out)->toBeNull();
});

it('still closes the latest open record with a check-out made after it', function () {
    $morning = lateOutOpen($this->tenant, $this->employee, '08:00');
    $afternoon = lateOutOpen($this->tenant, $this->employee, '13:00');

    lateOutPunch($this->tenant, $this->employee, '17:00');

    expect($afternoon->fresh()->check_out?->equalTo(lateOutAddis('17:00')))->toBeTrue()
        ->and($morning->fresh()->check_out)->toBeNull();
});
