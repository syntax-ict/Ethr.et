<?php

declare(strict_types=1);

use App\Enums\AttendanceSource;
use App\Enums\CorrectionStatus;
use App\Enums\UserRole;
use App\Models\AttendanceCorrection;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use App\Services\Attendance\AttendanceEngine;
use App\Services\Attendance\AttendanceInput;
use Carbon\Carbon;

/**
 * `attendance.checked_in`, `attendance.checked_out` and `attendance.corrected`:
 * until 2026-10-14 the nine webhook events covered employees, leave and
 * payroll, and an integration that wanted punches had to poll.
 */
afterEach(fn () => Carbon::setTestNow());

/** @param  list<string>  $events */
function attendanceHook(object $tenant, array $events): Webhook
{
    return Webhook::factory()->create([
        'tenant_id' => $tenant->id,
        'url' => 'https://hooks.example.et/attendance',
        'events' => $events,
        'is_active' => true,
    ]);
}

/** @return list<array<string, mixed>> the payloads queued, oldest first */
function attendanceDeliveries(string $event): array
{
    return WebhookDelivery::withoutGlobalScopes()->where('event', $event)->orderBy('id')
        ->get()->map(fn (WebhookDelivery $d) => $d->payload)->all();
}

const ATTENDANCE_DATA_KEYS = ['check_in', 'check_out', 'date', 'employee_name', 'employee_public_id', 'public_id', 'source', 'status'];

it('announces a check-in and a check-out, naming the record and the person and nothing else', function () {
    Carbon::setTestNow('2026-10-14 06:00:00');
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Hana Tesfaye']);
    test()->actingAs(createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant));
    attendanceHook($tenant, ['attendance.checked_in', 'attendance.checked_out']);
    $base = "http://{$tenant->subdomain}.ethr.test/api/v1/attendance";

    $publicId = test()->postJson("{$base}/check-in", [
        'idempotency_key' => 'hook-in',
        'latitude' => 9.0108,
        'longitude' => 38.7613,
    ])->assertCreated()->json('public_id');

    Carbon::setTestNow('2026-10-14 14:00:00');
    test()->postJson("{$base}/check-out", ['idempotency_key' => 'hook-out'])->assertOk();

    [$in] = attendanceDeliveries('attendance.checked_in');
    [$out] = attendanceDeliveries('attendance.checked_out');

    expect($in['event'])->toBe('attendance.checked_in')
        ->and(array_keys($in['data']))->toEqualCanonicalizing(ATTENDANCE_DATA_KEYS)
        ->and($in['data']['public_id'])->toBe($publicId)
        ->and($in['data']['employee_public_id'])->toBe($employee->public_id)
        ->and($in['data']['employee_name'])->toBe('Hana Tesfaye')
        ->and($in['data']['date'])->toBe('2026-10-14')
        ->and($in['data']['check_out'])->toBeNull()
        ->and($in['data']['source'])->toBe('web')
        ->and(json_encode($in))->not->toContain('38.7613');

    expect(array_keys($out['data']))->toEqualCanonicalizing(ATTENDANCE_DATA_KEYS)
        ->and($out['data']['public_id'])->toBe($publicId)
        ->and($out['data']['check_out'])->toBe('2026-10-14T14:00:00+00:00');
});

it('does not announce a replayed punch twice', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    test()->actingAs(createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant));
    attendanceHook($tenant, ['attendance.checked_in']);
    $url = "http://{$tenant->subdomain}.ethr.test/api/v1/attendance/check-in";

    test()->postJson($url, ['idempotency_key' => 'same'])->assertCreated();
    test()->postJson($url, ['idempotency_key' => 'same'])->assertOk()->assertJsonPath('was_duplicate', true);

    expect(attendanceDeliveries('attendance.checked_in'))->toHaveCount(1);
});

it('does not announce a punch that joined the day\'s existing record', function () {
    Carbon::setTestNow('2026-10-14 06:00:00');
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    attendanceHook($tenant, ['attendance.checked_in']);
    $engine = app(AttendanceEngine::class);
    $punch = fn (AttendanceSource $source, string $key) => $engine->record(new AttendanceInput(
        employeeId: $employee->id,
        tenantId: $tenant->id,
        source: $source,
        type: 'check_in',
        idempotencyKey: $key,
    ));

    $punch(AttendanceSource::BIOMETRIC, 'device-1');
    Carbon::setTestNow('2026-10-14 06:02:00');
    $punch(AttendanceSource::MOBILE, 'phone-1');

    expect(AttendanceRecord::withoutGlobalScopes()->where('employee_id', $employee->id)->where('status', '!=', 'voided')->count())->toBe(1)
        ->and(attendanceDeliveries('attendance.checked_in'))->toHaveCount(1);
});

it('announces a second session kept beside the first, so its check-out never arrives alone', function () {
    // Found running it live: a web check-in hours after a device punch is kept
    // as its own record and flagged for review. It sent no checked_in, and its
    // check-out then announced a record the integration had never seen.
    Carbon::setTestNow('2026-10-14 05:00:00');
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    attendanceHook($tenant, ['attendance.checked_in', 'attendance.checked_out']);
    $engine = app(AttendanceEngine::class);
    $punch = fn (AttendanceSource $source, string $type, string $key) => $engine->record(new AttendanceInput(
        employeeId: $employee->id,
        tenantId: $tenant->id,
        source: $source,
        type: $type,
        idempotencyKey: $key,
    ));

    $punch(AttendanceSource::BIOMETRIC, 'check_in', 'morning-in');
    Carbon::setTestNow('2026-10-14 08:00:00');
    $punch(AttendanceSource::BIOMETRIC, 'check_out', 'morning-out');
    Carbon::setTestNow('2026-10-14 15:00:00');
    $punch(AttendanceSource::WEB, 'check_in', 'evening-in');
    Carbon::setTestNow('2026-10-14 15:05:00');
    $punch(AttendanceSource::WEB, 'check_out', 'evening-out');

    $in = array_map(fn ($p) => $p['data']['public_id'], attendanceDeliveries('attendance.checked_in'));
    $out = array_map(fn ($p) => $p['data']['public_id'], attendanceDeliveries('attendance.checked_out'));

    expect($in)->toHaveCount(2)
        ->and($out)->toBe($in);
});

it('announces an approved correction with the corrected times', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $approver = Employee::factory()->create(['tenant_id' => $tenant->id]);
    actingAsUser(['role' => UserRole::HR_ADMIN, 'employee_id' => $approver->id], $tenant);
    attendanceHook($tenant, ['attendance.corrected']);
    $record = AttendanceRecord::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'date' => '2026-10-13',
        'check_in' => '2026-10-13 09:00:00',
        'check_out' => '2026-10-13 17:00:00',
    ]);
    $correction = AttendanceCorrection::factory()->create([
        'tenant_id' => $tenant->id,
        'attendance_record_id' => $record->id,
        'employee_id' => $employee->id,
        'proposed_check_in' => '2026-10-13 06:00:00',
        'status' => CorrectionStatus::PENDING,
    ]);

    test()->putJson("http://{$tenant->subdomain}.ethr.test/api/v1/attendance/corrections/{$correction->public_id}/approve")
        ->assertOk();

    [$corrected] = attendanceDeliveries('attendance.corrected');
    expect(array_keys($corrected['data']))->toEqualCanonicalizing([...ATTENDANCE_DATA_KEYS, 'correction_public_id'])
        ->and($corrected['data']['public_id'])->toBe($record->public_id)
        ->and($corrected['data']['correction_public_id'])->toBe($correction->public_id)
        ->and($corrected['data']['check_in'])->toBe('2026-10-13T06:00:00+00:00');
});

it('sends nothing to a webhook that did not subscribe to attendance', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    test()->actingAs(createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant));
    attendanceHook($tenant, ['employee.created']);

    test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/attendance/check-in", ['idempotency_key' => 'quiet'])
        ->assertCreated();

    expect(WebhookDelivery::withoutGlobalScopes()->count())->toBe(0);
});
