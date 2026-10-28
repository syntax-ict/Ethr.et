<?php

declare(strict_types=1);

use App\Enums\AttendanceMode;
use App\Enums\AttendanceSource;
use App\Enums\EmployeeStatus;
use App\Enums\UserRole;
use App\Models\AttendanceDevice;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSetting;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\BranchAttendanceOverride;
use App\Models\Employee;
use App\Models\Shift;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Attendance\AttendanceEngine;
use App\Services\Attendance\AttendanceInput;
use App\Services\Attendance\OfflineGrant;
use Carbon\Carbon;
use Tests\Support\SoftAuthenticator;

/**
 * Registered attendance devices: a self-service punch must carry a fresh
 * signature from the employee's own passkey (AttendanceDeviceGate), so a
 * friend's phone, a second phone or another browser cannot punch for them.
 */
afterEach(fn () => Carbon::setTestNow());

const DB_AT_WORK = ['latitude' => 9.0108, 'longitude' => 38.7613, 'accuracy' => 12];

/** @return array{0: Tenant, 1: Employee, 2: User, 3: Branch} */
function dbOrganisation(string $binding = 'enforce', array $settings = []): array
{
    Carbon::setTestNow(Carbon::parse('2026-10-26 08:20', 'Africa/Addis_Ababa')->utc());
    $tenant = createTenant();
    $branch = Branch::factory()->create(['tenant_id' => $tenant->id, 'latitude' => 9.0108, 'longitude' => 38.7613, 'geofence_radius_meters' => 150]);
    Shift::factory()->default()->create(['tenant_id' => $tenant->id, 'start_time' => '08:30', 'end_time' => '17:30']);
    AttendanceSetting::create(['tenant_id' => $tenant->id, ...AttendanceSetting::defaults(), 'device_binding' => $binding, 'attendance_rp_id' => 'ethr.test', ...$settings]);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id]);
    $user = createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant);
    test()->actingAs($user);

    return [$tenant, $employee, $user, $branch];
}

function dbUrl(Tenant $tenant, string $path): string
{
    return "http://{$tenant->subdomain}.ethr.test/api/v1/{$path}";
}

/** Register $auth for the signed-in employee; returns the response. */
function dbRegister(Tenant $tenant, SoftAuthenticator $auth, array $where = DB_AT_WORK, string $install = 'install-a')
{
    $options = test()->postJson(dbUrl($tenant, 'attendance/device/register-options'))->assertOk()->json('options');

    return test()->postJson(dbUrl($tenant, 'attendance/device/register'), [
        'credential' => $auth->register($options),
        'device_id' => $install,
        'label' => 'Test phone',
        ...$where,
    ]);
}

/** A signed proof for one punch. */
function dbProof(Tenant $tenant, SoftAuthenticator $auth): array
{
    $options = test()->postJson(dbUrl($tenant, 'attendance/device/challenge'))->assertOk()->json('options');

    return $auth->sign($options['rpId'], $options['challenge']);
}

function dbCheckIn(Tenant $tenant, ?array $proof, string $key = 'db-1', string $install = 'install-a')
{
    return test()->postJson(dbUrl($tenant, 'attendance/mobile/check-in'), array_filter([
        'idempotency_key' => $key,
        'device_id' => $install,
        'device_proof' => $proof,
        ...DB_AT_WORK,
    ], fn ($v) => $v !== null));
}

test('the first phone registered at work is active at once', function () {
    [$tenant, $employee] = dbOrganisation();

    dbRegister($tenant, new SoftAuthenticator)->assertCreated()->assertJsonPath('status', 'active');

    $device = AttendanceDevice::where('employee_id', $employee->id)->sole();
    expect($device->status)->toBe('active')
        ->and($device->enrolled_inside_geofence)->toBeTrue()
        ->and($device->rp_id)->toBe('ethr.test');
});

test('a phone registered away from work waits for approval, and so does a second phone', function () {
    [$tenant, $employee] = dbOrganisation();

    dbRegister($tenant, new SoftAuthenticator, ['latitude' => 9.10, 'longitude' => 38.90, 'accuracy' => 10])
        ->assertCreated()->assertJsonPath('status', 'pending');

    dbRegister($tenant, new SoftAuthenticator)->assertCreated()->assertJsonPath('status', 'active');
    dbRegister($tenant, new SoftAuthenticator, install: 'install-b')->assertCreated()->assertJsonPath('status', 'pending');

    expect(AttendanceDevice::where('employee_id', $employee->id)->where('status', 'active')->count())->toBe(1);
});

test('a remote worker never registers by themselves', function () {
    [$tenant, $employee] = dbOrganisation();
    $employee->forceFill(['attendance_mode' => AttendanceMode::REMOTE])->save();

    dbRegister($tenant, new SoftAuthenticator)->assertCreated()->assertJsonPath('status', 'pending');
});

test('a browser already registered to a colleague cannot be registered again', function () {
    [$tenant, , , $branch] = dbOrganisation();
    dbRegister($tenant, new SoftAuthenticator, install: 'shared-phone')->assertCreated();

    $friend = Employee::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id]);
    test()->actingAs(createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $friend->id], $tenant));

    dbRegister($tenant, new SoftAuthenticator, install: 'shared-phone')
        ->assertStatus(422)
        ->assertJsonPath('code', 'device-belongs-to-another');
});

test('under enforce a punch without the registered phone is refused, and with it is recorded', function () {
    [$tenant, $employee] = dbOrganisation();

    dbCheckIn($tenant, null)->assertStatus(422)->assertJsonPath('code', 'device-not-registered')
        ->assertHeader('Content-Type', 'application/problem+json');

    $auth = new SoftAuthenticator;
    dbRegister($tenant, $auth)->assertCreated();

    dbCheckIn($tenant, dbProof($tenant, $auth))->assertCreated();
    $record = AttendanceRecord::where('employee_id', $employee->id)->sole();
    expect($record->metadata['device_verdict'])->toBe('verified');
});

test('a challenge is spent once, and a retried punch still returns the first record', function () {
    [$tenant] = dbOrganisation();
    $auth = new SoftAuthenticator;
    dbRegister($tenant, $auth)->assertCreated();

    $proof = dbProof($tenant, $auth);
    dbCheckIn($tenant, $proof, 'retry-1')->assertCreated();

    // The phone lost the response and retries with the same key.
    dbCheckIn($tenant, $proof, 'retry-1')->assertOk();

    // A replayed signature on a new punch is not accepted.
    test()->postJson(dbUrl($tenant, 'attendance/mobile/check-out'), [
        'idempotency_key' => 'replay-out', 'device_id' => 'install-a', 'device_proof' => $proof, ...DB_AT_WORK,
    ])->assertStatus(422)->assertJsonPath('code', 'device-proof-invalid');
});

test('a colleague signed in on a friend\'s phone cannot punch with the friend\'s passkey', function () {
    [$tenant, , , $branch] = dbOrganisation();
    $friendsPhone = new SoftAuthenticator;
    dbRegister($tenant, $friendsPhone)->assertCreated();

    $colleague = Employee::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id]);
    test()->actingAs(createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $colleague->id], $tenant));

    // The colleague has a phone of their own, asks for a challenge, and signs
    // it with the friend's passkey instead.
    AttendanceDevice::query()->create([
        'tenant_id' => $tenant->id, 'employee_id' => $colleague->id, 'credential_id' => 'x', 'credential_id_hash' => AttendanceDevice::hashCredential('x'),
        'public_key' => 'x', 'rp_id' => 'ethr.test', 'status' => 'active',
    ]);
    $challenge = test()->postJson(dbUrl($tenant, 'attendance/device/challenge'))->json('options.challenge');

    dbCheckIn($tenant, $friendsPhone->sign('ethr.test', $challenge), 'colleague-1')
        ->assertStatus(422)->assertJsonPath('code', 'device-belongs-to-another');
});

test('a signature from a look-alike domain is refused', function () {
    [$tenant] = dbOrganisation();
    $auth = new SoftAuthenticator;
    dbRegister($tenant, $auth)->assertCreated();

    $challenge = test()->postJson(dbUrl($tenant, 'attendance/device/challenge'))->json('options.challenge');

    dbCheckIn($tenant, $auth->sign('ethr.test', $challenge, 'https://notethr.test'))
        ->assertStatus(422)->assertJsonPath('code', 'device-proof-invalid');
});

test('under flag the punch is kept and scored for review', function () {
    [$tenant, $employee] = dbOrganisation('flag');

    dbCheckIn($tenant, null)->assertCreated();

    $record = AttendanceRecord::where('employee_id', $employee->id)->sole();
    expect($record->metadata['device_verdict'])->toBe('unregistered')
        ->and($record->risk_reasons)->toHaveKey('device_unverified');
});

test('off changes nothing', function () {
    [$tenant, $employee] = dbOrganisation('off');

    dbCheckIn($tenant, null)->assertCreated();

    expect(AttendanceRecord::where('employee_id', $employee->id)->sole()->metadata)->not->toHaveKey('device_verdict');
});

test('a branch can enforce while the organisation only flags', function () {
    [$tenant, , , $branch] = dbOrganisation('flag');
    BranchAttendanceOverride::query()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'overrides' => ['device_binding' => 'enforce']]);

    dbCheckIn($tenant, null)->assertStatus(422)->assertJsonPath('code', 'device-not-registered');
});

test('a phone waiting for approval is told so', function () {
    [$tenant] = dbOrganisation();
    dbRegister($tenant, new SoftAuthenticator, ['latitude' => 9.10, 'longitude' => 38.90, 'accuracy' => 10])->assertCreated();

    dbCheckIn($tenant, null)->assertStatus(422)->assertJsonPath('code', 'device-pending-approval');
});

test('an exempt employee punches without the phone, and the punch is flagged', function () {
    [$tenant, $employee] = dbOrganisation();
    $employee->forceFill(['attendance_device_exempt_until' => '2026-11-01'])->save();

    dbCheckIn($tenant, null)->assertCreated();

    $record = AttendanceRecord::where('employee_id', $employee->id)->sole();
    expect($record->metadata['device_verdict'])->toBe('exempt')
        ->and($record->risk_reasons)->toHaveKey('device_unverified');
});

test('a web punch from a listed office network is accepted without a passkey', function () {
    [$tenant, $employee] = dbOrganisation('enforce', ['office_networks' => ['127.0.0.0/8']]);

    test()->postJson(dbUrl($tenant, 'attendance/check-in'), ['idempotency_key' => 'web-1'])->assertCreated();

    expect(AttendanceRecord::where('employee_id', $employee->id)->sole()->metadata['device_verdict'])->toBe('office_pc');
});

test('kiosk and other shared sources are never asked for a phone', function () {
    [$tenant, $employee] = dbOrganisation();

    $result = app(AttendanceEngine::class)->record(new AttendanceInput(
        employeeId: $employee->id,
        tenantId: $tenant->id,
        source: AttendanceSource::KIOSK,
        type: 'check_in',
        idempotencyKey: 'kiosk-1',
    ));

    expect($result->record->exists)->toBeTrue();
});

test('an offline punch signed on the registered phone verifies at sync, and an edited one does not', function () {
    [$tenant, $employee] = dbOrganisation();
    $auth = new SoftAuthenticator;
    dbRegister($tenant, $auth)->assertCreated();

    $grant = test()->getJson(dbUrl($tenant, 'attendance/offline-grant'))->assertOk()->json();
    expect($grant['device_nonce'])->toBeString()->and($grant['credential_id'])->toBe($auth->credentialId);

    $record = [
        'idempotency_key' => 'off-1',
        'employee_public_id' => $employee->public_id,
        'type' => 'check_in',
        'timestamp' => Carbon::now('UTC')->subMinutes(5)->toIso8601String(),
        'latitude' => 9.0108,
        'longitude' => 38.7613,
        'offline_token' => $grant['grant'],
        'device_id' => 'install-a',
    ];
    $proof = $auth->sign('ethr.test', SoftAuthenticator::b64(OfflineGrant::proofChallenge($grant['device_nonce'], $record)));

    $edited = [...$record, 'idempotency_key' => 'off-2', 'latitude' => 9.2];
    $editedProof = $auth->sign('ethr.test', SoftAuthenticator::b64(OfflineGrant::proofChallenge($grant['device_nonce'], [...$edited, 'latitude' => 9.0108])));

    $results = test()->postJson(dbUrl($tenant, 'attendance/sync'), ['records' => [
        [...$record, 'device_proof' => $proof],
        [...$edited, 'device_proof' => $editedProof],
    ]])->assertOk()->json('results');

    expect($results[0]['status'])->toBe('created')
        ->and($results[1]['status'])->toBe('error')
        ->and($results[1]['code'])->toBe('device-proof-invalid');
});

test('HR approves a replacement phone, and the old one stops working', function () {
    [$tenant, $employee] = dbOrganisation();
    $old = new SoftAuthenticator;
    dbRegister($tenant, $old)->assertCreated();
    $replacement = new SoftAuthenticator;
    $pending = dbRegister($tenant, $replacement, install: 'install-b')->json('public_id');

    test()->actingAs(createUser(['role' => UserRole::HR_ADMIN], $tenant));
    test()->getJson(dbUrl($tenant, 'attendance/devices'))->assertOk()
        ->assertJsonPath('coverage.employees', 1)
        ->assertJsonPath('coverage.registered', 1)
        ->assertJsonPath('coverage.pending', 1);
    test()->postJson(dbUrl($tenant, "attendance/devices/{$pending}/approve"))->assertOk()->assertJsonPath('status', 'active');

    expect(AttendanceDevice::where('employee_id', $employee->id)->where('status', 'active')->sole()->public_id)->toBe($pending);
});

test('only people who manage attendance see and decide phones', function () {
    [$tenant] = dbOrganisation();

    test()->getJson(dbUrl($tenant, 'attendance/devices'))->assertForbidden();
});

test('the employee is told the rule that applies to them', function () {
    [$tenant] = dbOrganisation();

    test()->getJson(dbUrl($tenant, 'attendance/device'))->assertOk()
        ->assertJsonPath('binding', 'enforce')
        ->assertJsonPath('rp_id', 'ethr.test')
        ->assertJsonPath('host_allowed', true)
        ->assertJsonPath('device', null);
});

test('HR sets and ends an employee\'s exemption, and a date in the past is refused', function () {
    [$tenant, $employee] = dbOrganisation();
    test()->actingAs(createUser(['role' => UserRole::HR_ADMIN], $tenant));
    $url = dbUrl($tenant, "employees/{$employee->public_id}/attendance-device-exemption");

    test()->putJson($url, ['until' => '2026-11-15'])->assertOk()->assertJsonPath('exempt_until', '2026-11-15');
    test()->getJson(dbUrl($tenant, "employees/{$employee->public_id}"))->assertJsonPath('attendance_device_exempt_until', '2026-11-15');
    test()->putJson($url, ['until' => '2026-10-01'])->assertStatus(422);
    test()->putJson($url, ['until' => null])->assertOk()->assertJsonPath('exempt_until', null);
});

test('a leaver\'s phone is revoked and can be registered by the colleague it is handed to', function () {
    [$tenant, $employee, , $branch] = dbOrganisation();
    dbRegister($tenant, new SoftAuthenticator, install: 'handed-on')->assertCreated();
    dbRegister($tenant, new SoftAuthenticator, install: 'second')->assertCreated()->assertJsonPath('status', 'pending');

    $employee->forceFill(['status' => EmployeeStatus::RESIGNED])->save();

    expect(AttendanceDevice::where('employee_id', $employee->id)->pluck('status')->sort()->values()->all())->toBe(['rejected', 'revoked']);

    $colleague = Employee::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id]);
    test()->actingAs(createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $colleague->id], $tenant));
    dbRegister($tenant, new SoftAuthenticator, install: 'handed-on')->assertCreated()->assertJsonPath('status', 'active');
});

test('a phone left active by someone who left before the rule never blocks a colleague', function () {
    [$tenant, $employee, , $branch] = dbOrganisation();
    dbRegister($tenant, new SoftAuthenticator, install: 'old-phone')->assertCreated();
    Employee::withoutEvents(fn () => $employee->forceFill(['status' => EmployeeStatus::TERMINATED])->save());

    $colleague = Employee::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id]);
    test()->actingAs(createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $colleague->id], $tenant));

    dbRegister($tenant, new SoftAuthenticator, install: 'old-phone')->assertCreated();
});

test('coverage counts the people who work here now, and lists who still has no phone', function () {
    [$tenant, $employee, , $branch] = dbOrganisation();
    dbRegister($tenant, new SoftAuthenticator)->assertCreated();
    $leaver = Employee::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id]);
    AttendanceDevice::query()->create([
        'tenant_id' => $tenant->id, 'employee_id' => $leaver->id, 'credential_id' => 'l', 'credential_id_hash' => AttendanceDevice::hashCredential('l'),
        'public_key' => 'l', 'rp_id' => 'ethr.test', 'status' => 'active',
    ]);
    Employee::withoutEvents(fn () => $leaver->forceFill(['status' => EmployeeStatus::RETIRED])->save());

    $missing = Employee::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'name' => 'Abebe Missing']);
    Employee::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'attendance_device_exempt_until' => '2026-11-10']);
    $kioskBranch = Branch::factory()->create(['tenant_id' => $tenant->id]);
    BranchAttendanceOverride::query()->create(['tenant_id' => $tenant->id, 'branch_id' => $kioskBranch->id, 'overrides' => ['enabled_methods' => ['kiosk', 'biometric']]]);
    Employee::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $kioskBranch->id]);
    $offBranch = Branch::factory()->create(['tenant_id' => $tenant->id]);
    BranchAttendanceOverride::query()->create(['tenant_id' => $tenant->id, 'branch_id' => $offBranch->id, 'overrides' => ['device_binding' => 'off']]);
    Employee::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $offBranch->id]);

    test()->actingAs(createUser(['role' => UserRole::HR_ADMIN], $tenant));
    test()->getJson(dbUrl($tenant, 'attendance/devices'))->assertOk()
        ->assertJsonPath('coverage.employees', 5)
        ->assertJsonPath('coverage.registered', 1)
        ->assertJsonPath('coverage.checked', 2)
        ->assertJsonPath('coverage.missing', 1);

    test()->getJson(dbUrl($tenant, 'attendance/devices/unregistered'))->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.public_id', $missing->public_id)
        ->assertJsonPath('data.0.has_pending_phone', false);
});

test('a waiting replacement shows the phone it replaces, and approvers are told it is waiting', function () {
    [$tenant] = dbOrganisation();
    $hr = createUser(['role' => UserRole::HR_ADMIN], $tenant);
    dbRegister($tenant, new SoftAuthenticator)->assertCreated();
    dbRegister($tenant, new SoftAuthenticator, install: 'install-b')->assertCreated()->assertJsonPath('status', 'pending');

    expect($hr->notifications()->sole()->data)
        ->toMatchArray(['replacing' => true, 'url' => '/attendance/devices']);

    test()->actingAs($hr);
    test()->getJson(dbUrl($tenant, 'attendance/devices?status=pending'))->assertOk()
        ->assertJsonPath('data.0.replaces.label', 'Test phone');
});

test('revoking and refusing take a reason, which is audited and told to the employee', function () {
    [$tenant, $employee, $user] = dbOrganisation();
    $active = dbRegister($tenant, new SoftAuthenticator)->json('public_id');
    $waiting = dbRegister($tenant, new SoftAuthenticator, install: 'install-b')->json('public_id');

    test()->actingAs(createUser(['role' => UserRole::HR_ADMIN], $tenant));
    test()->postJson(dbUrl($tenant, "attendance/devices/{$waiting}/reject"), ['reason' => 'Not your phone'])->assertOk()->assertJsonPath('status', 'rejected');
    test()->deleteJson(dbUrl($tenant, "attendance/devices/{$active}"), ['reason' => 'Reported lost'])->assertOk()->assertJsonPath('status', 'revoked');
    test()->deleteJson(dbUrl($tenant, "attendance/devices/{$active}"))->assertStatus(409);

    expect(AuditLog::where('action', 'attendance.device_revoked')->sole()->payload['reason'])->toBe('Reported lost')
        ->and($user->notifications()->pluck('data')->pluck('reason')->sort()->values()->all())->toBe(['Not your phone', 'Reported lost']);
});
