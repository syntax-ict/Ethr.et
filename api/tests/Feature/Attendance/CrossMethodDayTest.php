<?php

declare(strict_types=1);

use App\Enums\AttendanceSource;
use App\Exceptions\NoOpenCheckIn;
use App\Models\AttendanceConflict;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSetting;
use App\Models\Employee;
use App\Models\Tenant;
use App\Services\Attendance\AttendanceEngine;
use App\Services\Attendance\AttendanceInput;
use App\Services\CurrentTenant;
use Carbon\Carbon;

/**
 * One person, one day, several methods: the punches share one record, a
 * departure reported twice is not a second record, and each method's punch is
 * kept on the record for the timeline.
 */
afterEach(fn () => Carbon::setTestNow());

function crossDay(): array
{
    $tenant = createTenant(['timezone' => 'Africa/Addis_Ababa']);
    AttendanceSetting::create([...AttendanceSetting::defaults(), 'tenant_id' => $tenant->id]);
    app(CurrentTenant::class)->set($tenant);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);

    return [$tenant, $employee];
}

function crossPunch(Tenant $tenant, Employee $employee, AttendanceSource $source, string $type, string $utc, ?string $key = null)
{
    return app(AttendanceEngine::class)->record(new AttendanceInput(
        employeeId: $employee->id,
        tenantId: $tenant->id,
        source: $source,
        type: $type,
        idempotencyKey: $key ?? 'cm-'.uniqid(),
        occurredAt: Carbon::parse($utc, 'UTC')->toIso8601String(),
    ));
}

test('a departure reported by a second method is the same departure', function () {
    [$tenant, $employee] = crossDay();

    crossPunch($tenant, $employee, AttendanceSource::WEB, 'check_in', '2026-11-02 05:30:00');
    $out = crossPunch($tenant, $employee, AttendanceSource::KIOSK, 'check_out', '2026-11-02 14:00:00');
    expect($out->wasDuplicate)->toBeFalse();

    // The gate reader sees the same exit two minutes later: explicit and auto.
    $again = crossPunch($tenant, $employee, AttendanceSource::BIOMETRIC, 'check_out', '2026-11-02 14:02:00');
    $auto = crossPunch($tenant, $employee, AttendanceSource::BIOMETRIC, 'auto', '2026-11-02 14:03:00');

    expect($again->wasDuplicate)->toBeTrue()
        ->and($auto->wasDuplicate)->toBeTrue()
        ->and($auto->punch)->toBe('check_out');
    expect(AttendanceRecord::query()->where('employee_id', $employee->id)->count())->toBe(1);
    expect(AttendanceConflict::query()->count())->toBe(0);
});

test('every method that touched the day is kept on its one record', function () {
    [$tenant, $employee] = crossDay();

    crossPunch($tenant, $employee, AttendanceSource::WEB, 'check_in', '2026-11-02 05:30:00');
    crossPunch($tenant, $employee, AttendanceSource::KIOSK, 'check_out', '2026-11-02 14:00:00');
    crossPunch($tenant, $employee, AttendanceSource::BIOMETRIC, 'check_out', '2026-11-02 14:02:00');

    $record = AttendanceRecord::query()->where('employee_id', $employee->id)->sole();
    $log = collect($record->metadata['punches']);

    expect($log->pluck('source')->all())->toBe(['web', 'kiosk', 'biometric'])
        ->and($log->pluck('outcome')->all())->toBe(['created', 'applied', 'duplicate']);
});

test('a check-out with nothing open and nothing just closed is still refused', function () {
    [$tenant, $employee] = crossDay();

    expect(fn () => crossPunch($tenant, $employee, AttendanceSource::WEB, 'check_out', '2026-11-02 14:00:00'))
        ->toThrow(NoOpenCheckIn::class);
});

test('a check-in from a second method a minute later leaves one live record', function () {
    [$tenant, $employee] = crossDay();

    crossPunch($tenant, $employee, AttendanceSource::MOBILE, 'check_in', '2026-11-02 05:30:00');
    crossPunch($tenant, $employee, AttendanceSource::KIOSK, 'check_in', '2026-11-02 05:31:00');

    expect(AttendanceRecord::query()->where('employee_id', $employee->id)->where('status', '!=', 'voided')->count())->toBe(1);
});
