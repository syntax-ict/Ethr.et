<?php

declare(strict_types=1);

use App\Enums\AttendanceSource;
use App\Enums\AttendanceStatus;
use App\Exceptions\NoOpenCheckIn;
use App\Models\AttendanceConflict;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSetting;
use App\Models\Employee;
use App\Models\Tenant;
use App\Services\Attendance\AttendanceEngine;
use App\Services\Attendance\AttendanceInput;
use App\Services\Attendance\PunchOutcome;
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

// Acceptance matrix A1 to A4: the day opened by one method and closed by
// another is one record that names both, whichever pair it is.
test('a day opened by one method and closed by another is one record', function (AttendanceSource $in, AttendanceSource $out) {
    [$tenant, $employee] = crossDay();

    $arrival = crossPunch($tenant, $employee, $in, 'check_in', '2026-11-02 05:30:00');
    $departure = crossPunch($tenant, $employee, $out, 'check_out', '2026-11-02 14:30:00', 'cm-out');

    expect($arrival->wasDuplicate)->toBeFalse()
        ->and($departure->wasDuplicate)->toBeFalse()
        ->and($departure->punch)->toBe('check_out');

    $record = AttendanceRecord::query()->where('employee_id', $employee->id)->sole();
    $log = collect($record->metadata['punches']);

    expect($record->source)->toBe($in)
        ->and($record->check_in->toDateTimeString())->toBe('2026-11-02 05:30:00')
        ->and($record->check_out->toDateTimeString())->toBe('2026-11-02 14:30:00')
        ->and($record->metadata['checkout_idempotency_key'])->toBe('cm-out')
        ->and($log->pluck('source')->all())->toBe([$in->value, $out->value])
        ->and($log->pluck('outcome')->all())->toBe(['created', 'applied']);
    expect(AttendanceConflict::query()->count())->toBe(0);
})->with([
    'biometric in, biometric out' => [AttendanceSource::BIOMETRIC, AttendanceSource::BIOMETRIC],
    'biometric in, phone out' => [AttendanceSource::BIOMETRIC, AttendanceSource::MOBILE],
    'phone in, QR out' => [AttendanceSource::MOBILE, AttendanceSource::QR],
    'kiosk in, biometric out' => [AttendanceSource::KIOSK, AttendanceSource::BIOMETRIC],
    'web in, kiosk out' => [AttendanceSource::WEB, AttendanceSource::KIOSK],
]);

// Acceptance matrix A5: one arrival seen by three methods, then a fourth an
// hour on. The first three fold into one record; the fourth is told the day is
// already open and by whom.
test('one arrival seen by three methods and a late fourth is one live record', function () {
    [$tenant, $employee] = crossDay();

    crossPunch($tenant, $employee, AttendanceSource::MOBILE, 'check_in', '2026-11-02 05:30:00');
    crossPunch($tenant, $employee, AttendanceSource::KIOSK, 'check_in', '2026-11-02 05:31:00');
    crossPunch($tenant, $employee, AttendanceSource::BIOMETRIC, 'check_in', '2026-11-02 05:33:00');
    $late = crossPunch($tenant, $employee, AttendanceSource::WEB, 'check_in', '2026-11-02 06:30:00');

    $live = AttendanceRecord::query()
        ->where('employee_id', $employee->id)
        ->where('status', '!=', AttendanceStatus::VOIDED)
        ->get();

    expect($live)->toHaveCount(1)
        ->and($live[0]->check_in->toDateTimeString())->toBe('2026-11-02 05:30:00')
        ->and($late->wasDuplicate)->toBeTrue()
        ->and($late->outcome?->code)->toBe(PunchOutcome::ALREADY_CHECKED_IN)
        ->and($late->record->is($live[0]))->toBeTrue();
    expect(AttendanceConflict::query()->count())->toBe(0);
});

// Acceptance matrix A7: the same punch resent under its own key, by any
// method, is answered with the record it made.
test('a resent punch is answered with its own record, in or out', function (AttendanceSource $source) {
    [$tenant, $employee] = crossDay();

    $first = crossPunch($tenant, $employee, $source, 'check_in', '2026-11-02 05:30:00', 'cm-in');
    $again = crossPunch($tenant, $employee, $source, 'check_in', '2026-11-02 05:30:00', 'cm-in');
    crossPunch($tenant, $employee, $source, 'check_out', '2026-11-02 14:30:00', 'cm-out');
    // Hours later, as a phone that never saw the answer would.
    $outAgain = crossPunch($tenant, $employee, $source, 'check_out', '2026-11-02 20:00:00', 'cm-out');

    expect($again->wasDuplicate)->toBeTrue()
        ->and($again->record->is($first->record))->toBeTrue()
        ->and($outAgain->wasDuplicate)->toBeTrue()
        ->and($outAgain->punch)->toBe('check_out')
        ->and($outAgain->record->check_out->toDateTimeString())->toBe('2026-11-02 14:30:00');
    expect(AttendanceRecord::query()->where('employee_id', $employee->id)->count())->toBe(1);
})->with([
    'web' => [AttendanceSource::WEB],
    'phone' => [AttendanceSource::MOBILE],
    'QR' => [AttendanceSource::QR],
    'kiosk' => [AttendanceSource::KIOSK],
    'biometric' => [AttendanceSource::BIOMETRIC],
]);
