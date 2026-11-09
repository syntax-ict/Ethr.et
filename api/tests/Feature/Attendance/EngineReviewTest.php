<?php

declare(strict_types=1);

use App\Enums\AttendanceSource;
use App\Enums\AttendanceStatus;
use App\Enums\UserRole;
use App\Events\AttendanceRecorded;
use App\Exceptions\AttendanceRefused;
use App\Exceptions\NoOpenCheckIn;
use App\Models\AttendanceIntegrityFlag;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSetting;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\Tenant;
use App\Services\Attendance\AttendanceEngine;
use App\Services\Attendance\AttendanceInput;
use App\Services\Attendance\AttendanceResult;
use App\Services\Attendance\PunchOutcome;
use App\Services\CurrentTenant;
use App\Support\PhotoConsent;
use Carbon\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

/**
 * Engine review, 2026-11-08: a night shift's record closed by the next
 * evening's arrival, a late arrival's early departure never counted, a
 * check-out with no location kept, and a punch half-saved when anything after
 * the insert failed. Times are UTC; the tenant is in Addis Ababa (UTC+3).
 */
afterEach(fn () => Carbon::setTestNow());

function reviewEmployee(string $start, string $end, array $settings = []): array
{
    $tenant = createTenant(['timezone' => 'Africa/Addis_Ababa']);
    AttendanceSetting::create([...AttendanceSetting::defaults(), 'tenant_id' => $tenant->id, ...$settings]);
    app(CurrentTenant::class)->set($tenant);

    $shift = Shift::factory()->create([
        'tenant_id' => $tenant->id,
        'start_time' => $start,
        'end_time' => $end,
        'crosses_midnight' => $end <= $start,
        'grace_minutes' => 10,
        'early_departure_minutes' => 15,
        'working_days' => '1,2,3,4,5,6,7',
    ]);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    ShiftAssignment::factory()->create([
        'tenant_id' => $tenant->id,
        'shift_id' => $shift->id,
        'assignable_type' => Employee::class,
        'assignable_id' => $employee->id,
        'effective_from' => '2026-10-01',
    ]);

    return [$tenant, $employee];
}

function reviewPunch(Tenant $tenant, Employee $employee, string $type, string $utc, AttendanceSource $source = AttendanceSource::BIOMETRIC, ?float $lat = null): AttendanceResult
{
    return app(AttendanceEngine::class)->record(new AttendanceInput(
        employeeId: $employee->id,
        tenantId: $tenant->id,
        source: $source,
        type: $type,
        idempotencyKey: 'rv-'.uniqid('', true),
        latitude: $lat,
        longitude: $lat === null ? null : 38.7525,
        occurredAt: Carbon::parse($utc, 'UTC')->toIso8601String(),
    ));
}

test('a night shift left open is not closed by the next evening\'s arrival', function () {
    [$tenant, $employee] = reviewEmployee('22:00', '06:00');

    // 22:00 Monday in, no exit scan on Tuesday morning.
    reviewPunch($tenant, $employee, 'auto', '2026-11-02 19:00:00');

    // 21:55 Tuesday: the reader that does not say in or out sees them arrive.
    $arrival = reviewPunch($tenant, $employee, 'auto', '2026-11-03 18:55:00');

    expect($arrival->punch)->toBe('check_in')
        ->and($arrival->record->date->format('Y-m-d'))->toBe('2026-11-03');
    expect(AttendanceRecord::query()->whereDate('date', '2026-11-02')->sole()->check_out)->toBeNull();
});

test('an explicit check-out the next evening cannot close a night shift either', function () {
    [$tenant, $employee] = reviewEmployee('22:00', '06:00');
    reviewPunch($tenant, $employee, 'check_in', '2026-11-02 19:00:00');

    expect(fn () => reviewPunch($tenant, $employee, 'check_out', '2026-11-03 18:55:00'))
        ->toThrow(NoOpenCheckIn::class);
});

test('a night shift still closes the morning it ends, past fourteen hours from an early check-in', function () {
    [$tenant, $employee] = reviewEmployee('18:00', '08:00');

    // 17:50 in, 08:10 out: fourteen hours and twenty minutes.
    reviewPunch($tenant, $employee, 'check_in', '2026-11-02 14:50:00');
    $out = reviewPunch($tenant, $employee, 'check_out', '2026-11-03 05:10:00');

    expect($out->punch)->toBe('check_out')
        ->and($out->record->date->format('Y-m-d'))->toBe('2026-11-02');
});

test('an auto punch long after yesterday\'s shift ended is today\'s arrival', function () {
    [$tenant, $employee] = reviewEmployee('08:00', '17:00');

    // In at 18:00 Monday for some evening work, never out.
    reviewPunch($tenant, $employee, 'auto', '2026-11-02 15:00:00');

    // 07:30 Tuesday is thirteen and a half hours later, inside MAX_OPEN_HOURS,
    // but fourteen and a half after Monday's shift ended.
    $arrival = reviewPunch($tenant, $employee, 'auto', '2026-11-03 04:30:00');

    expect($arrival->punch)->toBe('check_in')
        ->and($arrival->record->date->format('Y-m-d'))->toBe('2026-11-03');
});

test('an auto punch shortly after an evening shift crosses midnight still closes it', function () {
    [$tenant, $employee] = reviewEmployee('14:00', '22:00');

    reviewPunch($tenant, $employee, 'auto', '2026-11-02 11:00:00');
    // 01:00 Tuesday, three hours of overtime past 22:00.
    $out = reviewPunch($tenant, $employee, 'auto', '2026-11-02 22:00:00');

    expect($out->punch)->toBe('check_out')
        ->and($out->record->date->format('Y-m-d'))->toBe('2026-11-02');
});

test('a late arrival who leaves early is counted for both', function () {
    [$tenant, $employee] = reviewEmployee('08:00', '17:00');

    // 08:20 in (20 late), 14:00 out (180 early).
    $in = reviewPunch($tenant, $employee, 'check_in', '2026-11-02 05:20:00');
    $out = reviewPunch($tenant, $employee, 'check_out', '2026-11-02 11:00:00');

    expect($in->outcome->code)->toBe(PunchOutcome::CHECKED_IN_LATE)
        ->and($in->outcome->minutes)->toBe(20);
    expect($out->record->status)->toBe(AttendanceStatus::LATE)
        ->and($out->outcome->code)->toBe(PunchOutcome::CHECKED_OUT_EARLY)
        ->and($out->outcome->minutes)->toBe(180)
        ->and(app(AttendanceEngine::class)->missedMinutes($out->record->fresh()))->toBe(200);
});

test('a late arrival who works the full shift is counted for the lateness alone', function () {
    [$tenant, $employee] = reviewEmployee('08:00', '17:00');

    reviewPunch($tenant, $employee, 'check_in', '2026-11-02 05:20:00');
    $out = reviewPunch($tenant, $employee, 'check_out', '2026-11-02 14:00:00');

    expect($out->outcome->code)->toBe(PunchOutcome::CHECKED_OUT)
        ->and(app(AttendanceEngine::class)->missedMinutes($out->record->fresh()))->toBe(20);
});

test('a required geofence asks the phone for a location at check-out', function () {
    [$tenant, $employee] = reviewEmployee('08:00', '17:00', ['geofence_required' => true]);
    reviewPunch($tenant, $employee, 'check_in', '2026-11-02 05:00:00', AttendanceSource::MOBILE, 9.0192);

    expect(fn () => reviewPunch($tenant, $employee, 'check_out', '2026-11-02 14:00:00', AttendanceSource::MOBILE))
        ->toThrow(AttendanceRefused::class);
    expect(AttendanceRecord::query()->sole()->check_out)->toBeNull();
});

test('a check-out keeps where it was made', function () {
    [$tenant, $employee] = reviewEmployee('08:00', '17:00');
    reviewPunch($tenant, $employee, 'check_in', '2026-11-02 05:00:00', AttendanceSource::MOBILE, 9.0192);

    $out = reviewPunch($tenant, $employee, 'check_out', '2026-11-02 19:00:00', AttendanceSource::MOBILE, 9.0300);

    expect($out->record->fresh()->metadata)
        ->toHaveKey('checkout_latitude', 9.03)
        ->toHaveKey('checkout_longitude', 38.7525);
});

/** A placed branch, a phone check-in inside it, a check-out 2.2 km away. */
function outsideCheckOut(bool $required): AttendanceRecord
{
    [$tenant, $employee] = reviewEmployee('08:00', '17:00', ['geofence_required' => $required]);
    $branch = Branch::factory()->create([
        'tenant_id' => $tenant->id, 'latitude' => 9.0108, 'longitude' => 38.7613, 'geofence_radius_meters' => 100,
    ]);
    $employee->update(['branch_id' => $branch->id]);

    $at = fn (string $type, string $utc, float $lat) => app(AttendanceEngine::class)->record(new AttendanceInput(
        employeeId: $employee->id,
        tenantId: $tenant->id,
        source: AttendanceSource::MOBILE,
        type: $type,
        idempotencyKey: 'rv-'.uniqid('', true),
        latitude: $lat,
        longitude: 38.7613,
        occurredAt: Carbon::parse($utc, 'UTC')->toIso8601String(),
    ));

    $at('check_in', '2026-11-02 05:00:00', 9.0108);

    return $at('check_out', '2026-11-02 19:00:00', 9.0308)->record->fresh();
}

test('a check-out outside a required geofence is a serious finding, so payroll lists it', function () {
    $record = outsideCheckOut(required: true);

    $flag = AttendanceIntegrityFlag::query()->where('attendance_record_id', $record->id)->where('type', 'outside_geofence')->sole();
    expect($flag->punch)->toBe('check_out')
        ->and($flag->severity)->toBe('high')
        ->and($record->metadata['checkout_geofence_verified'])->toBeFalse();
});

test('a check-out outside a geofence that is not required stays a medium finding', function () {
    $record = outsideCheckOut(required: false);

    expect(AttendanceIntegrityFlag::query()->where('attendance_record_id', $record->id)->where('type', 'outside_geofence')->sole()->severity)
        ->toBe('medium');
});

test('a punch that fails after its insert leaves no record and no photo, and its retry is made afresh', function () {
    Storage::fake('local');
    $tenant = createTenant();
    AttendanceSetting::create(['tenant_id' => $tenant->id, ...AttendanceSetting::defaults()]);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $user = createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant);
    PhotoConsent::give($user);
    test()->actingAs($user);

    $send = fn () => test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/attendance/mobile/check-in", [
        'idempotency_key' => 'half-saved',
        'latitude' => 9.0192,
        'longitude' => 38.7525,
        'photo' => selfieDataUrl(),
    ]);

    // Anything after the insert: here a listener of the punch's own event.
    // The controller reports it and answers with a sentence (A-Z review D-10).
    Event::listen(AttendanceRecorded::class, fn () => throw new RuntimeException('after the insert'));
    $send()->assertStatus(422);

    expect(AttendanceRecord::query()->count())->toBe(0)
        ->and(array_filter(Storage::disk('local')->allFiles(), fn (string $p) => str_contains($p, '/selfies/')))->toBe([]);

    Event::forget(AttendanceRecorded::class);
    $send()->assertStatus(201);

    $record = AttendanceRecord::query()->sole();
    expect($record->metadata['punches'] ?? [])->not->toBe([])
        ->and(Storage::disk('local')->exists($record->photo_path))->toBeTrue();
});
