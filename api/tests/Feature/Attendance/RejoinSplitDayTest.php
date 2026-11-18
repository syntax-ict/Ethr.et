<?php

declare(strict_types=1);

use App\Enums\AttendanceSource;
use App\Enums\AttendanceStatus;
use App\Enums\UserRole;
use App\Models\AttendanceConflict;
use App\Models\AttendanceIntegrityFlag;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSetting;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Device;
use App\Models\Employee;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\Tenant;
use App\Models\UnmatchedDevicePunch;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use App\Services\Attendance\AttendanceEngine;
use App\Services\Attendance\AttendanceInput;
use App\Services\Attendance\AttendanceResult;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * A person who works two sessions a day (a morning and an afternoon, a lunch
 * between) sends in, out, in, out. Until 2026-11-18 the second check-in opened
 * a second record, flagged for review: the morning was an early leave, the
 * afternoon "late by hours", and one person's replay made 46 conflicts.
 *
 * A return from a break inside the same day, within MAX_BREAK_MINUTES and
 * before the shift's end, reopens the day's record instead.
 *
 * Monday 12 October 2026 in Addis Ababa (UTC+3). The shift is 08:30-17:30
 * with ten minutes' grace, an hour's fixed break, and forty-five minutes before
 * the end counting as leaving early.
 */
afterEach(fn () => Carbon::setTestNow());

/** @return array{0: Tenant, 1: Employee} */
function rejoinSetup(): array
{
    $tenant = createTenant(['timezone' => 'Africa/Addis_Ababa']);
    AttendanceSetting::create([...AttendanceSetting::defaults(), 'tenant_id' => $tenant->id]);
    $shift = Shift::factory()->create([
        'tenant_id' => $tenant->id,
        'start_time' => '08:30',
        'end_time' => '17:30',
        'crosses_midnight' => false,
        'grace_minutes' => 10,
        'early_departure_minutes' => 45,
        'break_minutes' => 60,
        'working_days' => '1,2,3,4,5,6,7',
    ]);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'employee_code' => 'RJ-1']);
    ShiftAssignment::factory()->create([
        'tenant_id' => $tenant->id,
        'shift_id' => $shift->id,
        'assignable_type' => Employee::class,
        'assignable_id' => $employee->id,
        'effective_from' => '2026-10-01',
    ]);

    return [$tenant, $employee];
}

function rejoinPunch(Tenant $tenant, Employee $employee, AttendanceSource $source, string $type, string $local, ?string $key = null, string $date = '2026-10-12'): AttendanceResult
{
    $at = Carbon::parse("{$date} {$local}", 'Africa/Addis_Ababa')->utc();
    Carbon::setTestNow($at);

    return app(AttendanceEngine::class)->record(new AttendanceInput(
        employeeId: $employee->id,
        tenantId: $tenant->id,
        source: $source,
        type: $type,
        idempotencyKey: $key ?? "rj-{$type}-{$local}-{$source->value}",
        occurredAt: $at->toIso8601String(),
    ));
}

/** @return Collection<int, AttendanceRecord> */
function rejoinRecords(Employee $employee)
{
    return AttendanceRecord::withoutGlobalScopes()
        ->where('employee_id', $employee->id)
        ->where('status', '!=', AttendanceStatus::VOIDED)
        ->orderBy('check_in')
        ->get();
}

function rejoinLocal(?Carbon $moment): ?string
{
    return $moment?->copy()->setTimezone('Africa/Addis_Ababa')->format('H:i');
}

/** @return list<array<string, string>> */
function rejoinBreaks(AttendanceRecord $record): array
{
    $breaks = array_values((array) ($record->fresh()->metadata['breaks'] ?? []));
    foreach ($breaks as $i => $entry) {
        ksort($entry);
        $breaks[$i] = $entry;
    }

    return $breaks;
}

function rejoinHook(Tenant $tenant, string $event): void
{
    Webhook::factory()->create([
        'tenant_id' => $tenant->id,
        'url' => 'https://hooks.example.et/rejoin',
        'events' => [$event],
        'is_active' => true,
    ]);
}

// ── The Habru day ───────────────────────────────────────────────────────────

it('joins in, out, in, out into one record with the lunch noted as a break', function () {
    [$tenant, $employee] = rejoinSetup();
    $bio = AttendanceSource::BIOMETRIC;

    rejoinPunch($tenant, $employee, $bio, 'check_in', '08:20');
    rejoinPunch($tenant, $employee, $bio, 'check_out', '12:05');
    $back = rejoinPunch($tenant, $employee, $bio, 'check_in', '13:55');
    rejoinPunch($tenant, $employee, $bio, 'check_out', '17:00');

    $records = rejoinRecords($employee);
    expect($records)->toHaveCount(1)
        ->and(AttendanceConflict::withoutGlobalScopes()->count())->toBe(0)
        ->and($back->outcome?->code)->toBe('resumed')
        ->and($back->wasDuplicate)->toBeFalse();

    $record = $records->first();
    $breaks = rejoinBreaks($record);
    expect(rejoinLocal($record->check_in))->toBe('08:20')
        ->and(rejoinLocal($record->check_out))->toBe('17:00')
        ->and($record->status)->toBe(AttendanceStatus::PRESENT)
        ->and($breaks)->toHaveCount(2)
        ->and($breaks[0]['type'])->toBe('out')
        ->and(rejoinLocal(Carbon::parse($breaks[0]['at'])))->toBe('12:05')
        ->and($breaks[0]['key'])->toBe('rj-check_out-12:05-biometric')
        ->and($breaks[1]['type'])->toBe('in')
        ->and(rejoinLocal(Carbon::parse($breaks[1]['at'])))->toBe('13:55')
        ->and($breaks[1]['key'])->toBe('rj-check_in-13:55-biometric')
        // The shift's fixed break is taken once, as for every one-record day.
        ->and($record->workedMinutes())->toBe((8 * 60 + 40) - 60);
});

it('keeps a late arrival late across the rejoin', function () {
    [$tenant, $employee] = rejoinSetup();
    $bio = AttendanceSource::BIOMETRIC;

    rejoinPunch($tenant, $employee, $bio, 'check_in', '08:50');
    rejoinPunch($tenant, $employee, $bio, 'check_out', '12:05');
    rejoinPunch($tenant, $employee, $bio, 'check_in', '13:55');
    rejoinPunch($tenant, $employee, $bio, 'check_out', '17:00');

    $record = rejoinRecords($employee)->sole();
    expect($record->status)->toBe(AttendanceStatus::LATE)
        ->and(rejoinLocal($record->check_in))->toBe('08:50')
        ->and(rejoinLocal($record->check_out))->toBe('17:00');
});

it('undoes the morning early leave on return, and decides it again at the last check-out', function () {
    [$tenant, $employee] = rejoinSetup();
    $bio = AttendanceSource::BIOMETRIC;

    rejoinPunch($tenant, $employee, $bio, 'check_in', '08:20');
    rejoinPunch($tenant, $employee, $bio, 'check_out', '12:05');
    expect(rejoinRecords($employee)->sole()->status)->toBe(AttendanceStatus::EARLY_LEAVE);

    rejoinPunch($tenant, $employee, $bio, 'check_in', '13:55');
    $reopened = rejoinRecords($employee)->sole();
    expect($reopened->status)->toBe(AttendanceStatus::PRESENT)
        ->and($reopened->check_out)->toBeNull();

    $out = rejoinPunch($tenant, $employee, $bio, 'check_out', '16:00');
    expect(rejoinRecords($employee)->sole()->status)->toBe(AttendanceStatus::EARLY_LEAVE)
        ->and($out->outcome?->code)->toBe('checked_out_early');
});

it('does not call a full afternoon an early leave', function () {
    [$tenant, $employee] = rejoinSetup();
    $bio = AttendanceSource::BIOMETRIC;

    rejoinPunch($tenant, $employee, $bio, 'check_in', '08:20');
    rejoinPunch($tenant, $employee, $bio, 'check_out', '12:05');
    rejoinPunch($tenant, $employee, $bio, 'check_in', '13:55');
    $out = rejoinPunch($tenant, $employee, $bio, 'check_out', '17:40');

    expect(rejoinRecords($employee)->sole()->status)->toBe(AttendanceStatus::PRESENT)
        ->and($out->outcome?->code)->toBe('checked_out');
});

it('joins a morning on the terminal and an afternoon on the phone, or the web', function (AttendanceSource $afternoon) {
    [$tenant, $employee] = rejoinSetup();

    rejoinPunch($tenant, $employee, AttendanceSource::BIOMETRIC, 'check_in', '08:20');
    rejoinPunch($tenant, $employee, AttendanceSource::BIOMETRIC, 'check_out', '12:05');
    rejoinPunch($tenant, $employee, $afternoon, 'check_in', '13:55');
    rejoinPunch($tenant, $employee, $afternoon, 'check_out', '17:00');

    $record = rejoinRecords($employee)->sole();
    expect(AttendanceConflict::withoutGlobalScopes()->count())->toBe(0)
        ->and(rejoinLocal($record->check_out))->toBe('17:00')
        ->and(rejoinBreaks($record))->toHaveCount(2);
})->with([AttendanceSource::MOBILE, AttendanceSource::WEB]);

// ── The bounds ──────────────────────────────────────────────────────────────

it('keeps a return after the shift ended as a second session, flagged', function () {
    [$tenant, $employee] = rejoinSetup();
    $bio = AttendanceSource::BIOMETRIC;

    rejoinPunch($tenant, $employee, $bio, 'check_in', '08:20');
    rejoinPunch($tenant, $employee, $bio, 'check_out', '16:00');
    $again = rejoinPunch($tenant, $employee, $bio, 'check_in', '17:45');

    expect(rejoinRecords($employee))->toHaveCount(2)
        ->and($again->outcome?->code)->toBe('checked_in_again')
        ->and(AttendanceConflict::withoutGlobalScopes()->count())->toBe(1);
});

it('keeps a return more than three hours after leaving as a second session, flagged', function () {
    [$tenant, $employee] = rejoinSetup();
    $bio = AttendanceSource::BIOMETRIC;

    rejoinPunch($tenant, $employee, $bio, 'check_in', '08:20');
    rejoinPunch($tenant, $employee, $bio, 'check_out', '10:00');
    rejoinPunch($tenant, $employee, $bio, 'check_in', '13:01'); // 181 minutes

    expect(rejoinRecords($employee))->toHaveCount(2)
        ->and(AttendanceConflict::withoutGlobalScopes()->count())->toBe(1);
});

it('joins a return exactly three hours after leaving', function () {
    [$tenant, $employee] = rejoinSetup();
    $bio = AttendanceSource::BIOMETRIC;

    rejoinPunch($tenant, $employee, $bio, 'check_in', '08:20');
    rejoinPunch($tenant, $employee, $bio, 'check_out', '10:00');
    rejoinPunch($tenant, $employee, $bio, 'check_in', '13:00'); // 180 minutes

    expect(rejoinRecords($employee))->toHaveCount(1);
});

it('leaves a manual entry after the check-out as it was', function () {
    [$tenant, $employee] = rejoinSetup();

    rejoinPunch($tenant, $employee, AttendanceSource::BIOMETRIC, 'check_in', '08:20');
    rejoinPunch($tenant, $employee, AttendanceSource::BIOMETRIC, 'check_out', '12:05');
    rejoinPunch($tenant, $employee, AttendanceSource::MANUAL, 'check_in', '13:55');

    expect(rejoinRecords($employee))->toHaveCount(2)
        ->and(rejoinRecords($employee)->first()->check_out)->not->toBeNull();
});

it('does not join an arrival on the next work day', function () {
    [$tenant, $employee] = rejoinSetup();
    $bio = AttendanceSource::BIOMETRIC;

    rejoinPunch($tenant, $employee, $bio, 'check_in', '08:20');
    rejoinPunch($tenant, $employee, $bio, 'check_out', '16:40');
    rejoinPunch($tenant, $employee, $bio, 'check_in', '08:20', 'rj-next-day', '2026-10-13');

    expect(rejoinRecords($employee))->toHaveCount(2);
});

// ── A terminal resends ──────────────────────────────────────────────────────

it('answers a resent morning check-out as a duplicate after the rejoin, and does not close the day', function () {
    [$tenant, $employee] = rejoinSetup();
    $bio = AttendanceSource::BIOMETRIC;

    rejoinPunch($tenant, $employee, $bio, 'check_in', '08:20');
    rejoinPunch($tenant, $employee, $bio, 'check_out', '12:05', 'morning-out');
    rejoinPunch($tenant, $employee, $bio, 'check_in', '13:55', 'afternoon-in');

    $resend = fn () => app(AttendanceEngine::class)->record(new AttendanceInput(
        employeeId: $employee->id,
        tenantId: $tenant->id,
        source: $bio,
        type: 'check_out',
        idempotencyKey: 'morning-out',
        occurredAt: Carbon::parse('2026-10-12 12:05', 'Africa/Addis_Ababa')->utc()->toIso8601String(),
    ));

    Carbon::setTestNow(Carbon::parse('2026-10-12 14:10', 'Africa/Addis_Ababa'));
    $resent = $resend();

    expect($resent->wasDuplicate)->toBeTrue()
        ->and(rejoinRecords($employee))->toHaveCount(1)
        ->and(rejoinRecords($employee)->sole()->check_out)->toBeNull();

    // And once the day is closed, the morning's key still answers.
    rejoinPunch($tenant, $employee, $bio, 'check_out', '17:00', 'evening-out');
    $again = $resend();

    $record = rejoinRecords($employee)->sole();
    expect($again->wasDuplicate)->toBeTrue()
        ->and(rejoinLocal($record->check_out))->toBe('17:00');
});

it('answers a resent afternoon check-in as idempotent, open or closed', function () {
    [$tenant, $employee] = rejoinSetup();
    $bio = AttendanceSource::BIOMETRIC;

    rejoinPunch($tenant, $employee, $bio, 'check_in', '08:20');
    rejoinPunch($tenant, $employee, $bio, 'check_out', '12:05');
    rejoinPunch($tenant, $employee, $bio, 'check_in', '13:55', 'afternoon-in');

    $open = rejoinPunch($tenant, $employee, $bio, 'check_in', '13:55', 'afternoon-in');
    expect($open->wasDuplicate)->toBeTrue()
        ->and(rejoinRecords($employee))->toHaveCount(1)
        ->and(rejoinBreaks(rejoinRecords($employee)->sole()))->toHaveCount(2);

    rejoinPunch($tenant, $employee, $bio, 'check_out', '17:00');
    $closed = rejoinPunch($tenant, $employee, $bio, 'check_in', '13:55', 'afternoon-in');

    expect($closed->wasDuplicate)->toBeTrue()
        ->and(rejoinRecords($employee))->toHaveCount(1)
        ->and(AttendanceConflict::withoutGlobalScopes()->count())->toBe(0);
});

// ── What the rejoin leaves behind ───────────────────────────────────────────

it('logs the return as rejoined, audits it, and announces the check-in', function () {
    [$tenant, $employee] = rejoinSetup();
    rejoinHook($tenant, 'attendance.checked_in');
    $bio = AttendanceSource::BIOMETRIC;

    rejoinPunch($tenant, $employee, $bio, 'check_in', '08:20');
    rejoinPunch($tenant, $employee, $bio, 'check_out', '12:05');
    rejoinPunch($tenant, $employee, $bio, 'check_in', '13:55');

    $record = rejoinRecords($employee)->sole();
    $log = collect($record->metadata['punches'])->last();
    expect($log['kind'])->toBe('check_in')
        ->and($log['outcome'])->toBe('rejoined')
        ->and($log['source'])->toBe('biometric');

    $audit = AuditLog::withoutGlobalScopes()->where('action', 'attendance.day_rejoined')->sole();
    expect((int) $audit->auditable_id)->toBe($record->id)
        ->and($audit->payload['gap_minutes'])->toBe(110)
        ->and($audit->payload['source'])->toBe('biometric');

    $deliveries = WebhookDelivery::withoutGlobalScopes()->where('event', 'attendance.checked_in')->orderBy('id')->get();
    expect($deliveries)->toHaveCount(2)
        ->and($deliveries->last()->payload['data']['public_id'])->toBe($record->public_id);
});

it('says welcome back to the person, however late they first arrived', function () {
    [$tenant, $employee] = rejoinSetup();
    $bio = AttendanceSource::BIOMETRIC;

    rejoinPunch($tenant, $employee, $bio, 'check_in', '08:50');
    rejoinPunch($tenant, $employee, $bio, 'check_out', '12:05');
    $back = rejoinPunch($tenant, $employee, $bio, 'check_in', '13:55');

    expect($back->outcome?->code)->toBe('resumed');
});

it('joins held punches replayed in the order they arrived', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-12 18:00:00', 'Africa/Addis_Ababa'));
    [$tenant, $employee] = rejoinSetup();
    $device = Device::factory()->create([
        'branch_id' => Branch::factory()->create(['tenant_id' => $tenant->id])->id,
        'tenant_id' => $tenant->id,
        'adapter_type' => 'zkteco_adms',
        'serial_number' => 'RJ-SN-1',
        'connection_config' => ['mode' => 'adms'],
        'webhook_ip_allowlist' => ['127.0.0.1'],
        'status' => 'pending',
    ]);
    $body = "9001\t2026-10-12 08:50:00\t0\t1\t0\t0\t0\n"
        ."9001\t2026-10-12 12:05:00\t1\t1\t0\t0\t0\n"
        ."9001\t2026-10-12 13:55:00\t0\t1\t0\t0\t0\n"
        ."9001\t2026-10-12 17:00:00\t1\t1\t0\t0\t0\n";
    test()->call('POST', '/iclock/cdata?'.http_build_query(['SN' => 'RJ-SN-1', 'table' => 'ATTLOG']), [], [], [], ['CONTENT_TYPE' => 'text/plain'], $body)->assertOk();
    expect(UnmatchedDevicePunch::withoutGlobalScopes()->where('device_id', $device->id)->count())->toBe(4);

    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/devices/unmatched-punches/assign", [
        'device_public_id' => $device->public_id,
        'badge' => '9001',
        'employee_public_id' => $employee->public_id,
    ])->assertOk()->assertJsonPath('replayed', 4)->assertJsonPath('refused', 0);

    $record = rejoinRecords($employee)->sole();
    expect(rejoinLocal($record->check_in))->toBe('08:50')
        ->and(rejoinLocal($record->check_out))->toBe('17:00')
        ->and(rejoinBreaks($record))->toHaveCount(2)
        ->and(AttendanceConflict::withoutGlobalScopes()->count())->toBe(0);
});

// ── Where the return is compared with, and the morning's selfie ─────────────

function rejoinPunchAt(Tenant $tenant, Employee $employee, string $type, string $local, float $lat, float $lon, ?string $photo = null): AttendanceResult
{
    $at = Carbon::parse("2026-10-12 {$local}", 'Africa/Addis_Ababa')->utc();
    Carbon::setTestNow($at);

    return app(AttendanceEngine::class)->record(new AttendanceInput(
        employeeId: $employee->id,
        tenantId: $tenant->id,
        source: AttendanceSource::MOBILE,
        type: $type,
        idempotencyKey: "rj-at-{$type}-{$local}",
        latitude: $lat,
        longitude: $lon,
        photoPath: $photo,
        occurredAt: $at->toIso8601String(),
    ));
}

/** @return list<string> */
function rejoinTravelFlags(Employee $employee): array
{
    return AttendanceIntegrityFlag::withoutGlobalScopes()
        ->where('employee_id', $employee->id)
        ->where('type', 'impossible_travel')
        ->pluck('type')
        ->all();
}

it('flags a return from lunch made from 500 km away, on the day\'s own record', function () {
    [$tenant, $employee] = rejoinSetup();

    rejoinPunchAt($tenant, $employee, 'check_in', '08:30', 9.0108, 38.7613);
    rejoinPunchAt($tenant, $employee, 'check_out', '12:00', 9.0108, 38.7613);
    $back = rejoinPunchAt($tenant, $employee, 'check_in', '13:00', 13.4967, 39.4753); // Mekelle

    expect($back->outcome?->code)->toBe('resumed')
        ->and(rejoinRecords($employee))->toHaveCount(1)
        ->and(rejoinTravelFlags($employee))->toBe(['impossible_travel']);

    $out = rejoinBreaks(rejoinRecords($employee)->sole())[0];
    expect((float) $out['lat'])->toBe(9.0108)
        ->and((float) $out['lon'])->toBe(38.7613)
        ->and(rejoinRecords($employee)->sole()->metadata)->not->toHaveKeys(['checkout_latitude', 'checkout_longitude']);
});

it('raises no travel flag for a return from the same place', function () {
    [$tenant, $employee] = rejoinSetup();

    rejoinPunchAt($tenant, $employee, 'check_in', '08:30', 9.0108, 38.7613);
    rejoinPunchAt($tenant, $employee, 'check_out', '12:00', 9.0108, 38.7613);
    rejoinPunchAt($tenant, $employee, 'check_in', '13:00', 9.0112, 38.7615);

    expect(rejoinRecords($employee))->toHaveCount(1)
        ->and(rejoinTravelFlags($employee))->toBe([]);
});

it('keeps the morning check-out selfie in the break, and the last one as the check-out photo', function () {
    [$tenant, $employee] = rejoinSetup();

    rejoinPunchAt($tenant, $employee, 'check_in', '08:30', 9.0108, 38.7613);
    rejoinPunchAt($tenant, $employee, 'check_out', '12:00', 9.0108, 38.7613, 'selfies/morning-out.jpg');
    expect(rejoinRecords($employee)->sole()->metadata['checkout_photo_path'])->toBe('selfies/morning-out.jpg');

    rejoinPunchAt($tenant, $employee, 'check_in', '13:00', 9.0108, 38.7613);
    $reopened = rejoinRecords($employee)->sole();
    expect($reopened->metadata)->not->toHaveKey('checkout_photo_path')
        ->and(rejoinBreaks($reopened)[0]['photo'])->toBe('selfies/morning-out.jpg')
        ->and($reopened->metadata['break_photos'])->toBe(1)
        ->and($reopened->breakPhotoPaths())->toBe(['selfies/morning-out.jpg']);

    rejoinPunchAt($tenant, $employee, 'check_out', '17:00', 9.0108, 38.7613, 'selfies/final-out.jpg');
    $closed = rejoinRecords($employee)->sole();
    expect($closed->metadata['checkout_photo_path'])->toBe('selfies/final-out.jpg')
        ->and(rejoinBreaks($closed)[0]['photo'])->toBe('selfies/morning-out.jpg');
});
