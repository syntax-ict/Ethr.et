<?php

declare(strict_types=1);

use App\Enums\AttendanceSource;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSetting;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\KioskSession;
use App\Models\Tenant;
use App\Services\Attendance\AttendanceEngine;
use App\Services\Attendance\AttendanceInput;
use App\Services\CurrentTenant;
use Carbon\Carbon;
use Illuminate\Testing\TestResponse;

/**
 * The shared kiosk sends `auto` (2026-10-28): it cannot know, before the code
 * is typed, whether this person is arriving or leaving. It started on Check
 * in, so someone who checked in on the phone in the morning and typed their
 * code at the kiosk in the evening opened a second record flagged as a
 * conflict, and the day stayed open. The server decides from the day's
 * record, and says which punch it was so the kiosk greets the right way.
 */
function autoKiosk(): array
{
    $tenant = createTenant(['timezone' => 'Africa/Addis_Ababa']);
    AttendanceSetting::create([...AttendanceSetting::defaults(), 'tenant_id' => $tenant->id]);
    $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
    $employee = Employee::factory()->create([
        'tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'employee_code' => 'K-AUTO',
    ]);
    $session = KioskSession::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'status' => 'active']);

    return [$tenant, $session, $employee];
}

function autoPunch(Tenant $tenant, KioskSession $session, string $type = 'auto', ?string $key = null): TestResponse
{
    app(CurrentTenant::class)->forget();

    return test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/kiosk/check-in", [
        'employee_code' => 'K-AUTO',
        'type' => $type,
        'idempotency_key' => $key ?? 'kiosk-auto-'.uniqid(),
    ], ['X-Kiosk-Token' => $session->token]);
}

/** A check-in made on the phone this morning, still open. */
function phoneCheckIn(Tenant $tenant, Employee $employee, string $localTime): AttendanceRecord
{
    return AttendanceRecord::factory()->mobile()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'date' => '2026-10-28',
        'check_in' => Carbon::parse("2026-10-28 {$localTime}", 'Africa/Addis_Ababa')->utc(),
        'check_out' => null,
    ]);
}

afterEach(fn () => Carbon::setTestNow());

it('checks out a day the phone checked in, instead of opening a second record', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-28 17:05:00', 'Africa/Addis_Ababa'));
    [$tenant, $session, $employee] = autoKiosk();
    $morning = phoneCheckIn($tenant, $employee, '08:02:00');

    autoPunch($tenant, $session)
        ->assertOk()
        ->assertJsonPath('punch', 'check_out')
        ->assertJsonPath('was_duplicate', false)
        ->assertJsonPath('employee_name', $employee->name);

    expect(AttendanceRecord::withoutGlobalScopes()->where('employee_id', $employee->id)->count())->toBe(1)
        ->and($morning->fresh()->check_out)->not->toBeNull();
});

it('checks in when the day has no open record', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-28 08:01:00', 'Africa/Addis_Ababa'));
    [$tenant, $session, $employee] = autoKiosk();

    autoPunch($tenant, $session)
        ->assertCreated()
        ->assertJsonPath('punch', 'check_in');

    $record = AttendanceRecord::withoutGlobalScopes()->where('employee_id', $employee->id)->sole();
    expect($record->check_out)->toBeNull();
});

it('treats a second code typed minutes after a check-in as the same arrival', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-28 08:04:00', 'Africa/Addis_Ababa'));
    [$tenant, $session, $employee] = autoKiosk();
    $morning = phoneCheckIn($tenant, $employee, '08:02:00');

    autoPunch($tenant, $session)
        ->assertOk()
        ->assertJsonPath('punch', 'check_in')
        ->assertJsonPath('was_duplicate', true);

    expect($morning->fresh()->check_out)->toBeNull();
});

it('answers a retried check-out with the punch it was', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-28 17:05:00', 'Africa/Addis_Ababa'));
    [$tenant, $session, $employee] = autoKiosk();
    phoneCheckIn($tenant, $employee, '08:02:00');

    autoPunch($tenant, $session, key: 'kiosk-auto-retry')->assertOk()->assertJsonPath('punch', 'check_out');

    autoPunch($tenant, $session, key: 'kiosk-auto-retry')
        ->assertOk()
        ->assertJsonPath('punch', 'check_out')
        ->assertJsonPath('was_duplicate', true);
});

it('still honours a punch the person chose', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-28 17:05:00', 'Africa/Addis_Ababa'));
    [$tenant, $session, $employee] = autoKiosk();
    phoneCheckIn($tenant, $employee, '08:02:00');

    autoPunch($tenant, $session, 'check_out')
        ->assertOk()
        ->assertJsonPath('punch', 'check_out');
});

it('does not close a day with a punch made before it began', function () {
    // An offline terminal syncing late, or a device backlog: the punch is
    // older than the open record's check-in, so it cannot be its check-out.
    Carbon::setTestNow(Carbon::parse('2026-10-28 09:00:00', 'Africa/Addis_Ababa'));
    [$tenant, , $employee] = autoKiosk();
    $morning = phoneCheckIn($tenant, $employee, '08:30:00');

    $result = app(AttendanceEngine::class)->record(new AttendanceInput(
        employeeId: $employee->id,
        tenantId: $tenant->id,
        source: AttendanceSource::KIOSK,
        type: 'auto',
        idempotencyKey: 'kiosk-backlog-1',
        occurredAt: Carbon::parse('2026-10-28 08:00:00', 'Africa/Addis_Ababa')->toIso8601String(),
    ));

    expect($result->punch)->toBe('check_in')
        ->and($morning->fresh()->check_out)->toBeNull();
});
