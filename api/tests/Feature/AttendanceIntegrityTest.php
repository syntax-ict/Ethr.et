<?php

declare(strict_types=1);

use App\Enums\AttendanceStatus;
use App\Enums\UserRole;
use App\Models\AttendanceIntegrityFlag;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSetting;
use App\Models\Branch;
use App\Models\Device;
use App\Models\Employee;
use App\Models\KioskSession;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\AttendancePunchRejectedNotification;
use App\Services\Attendance\OfflineGrant;
use App\Services\Attendance\QrCodeService;
use App\Services\CurrentTenant;
use App\Support\DeviceTime;
use Carbon\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

/**
 * Attendance integrity: device clocks read in the right zone, QR codes used
 * once, kiosk PINs that cannot be guessed, offline punches the server can
 * vouch for - and everything questionable flagged for a person who decides,
 * with a rejection voiding the punch and telling the employee why.
 */
function integrityUrl(Tenant $tenant, string $path): string
{
    return "http://{$tenant->subdomain}.ethr.test/api/v1{$path}";
}

/** @return array{Employee, User} */
function integrityEmployee(Tenant $tenant, array $attributes = []): array
{
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, ...$attributes]);
    $user = createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant);

    return [$employee, $user];
}

function geofencedBranch(Tenant $tenant): Branch
{
    return Branch::factory()->create([
        'tenant_id' => $tenant->id,
        'latitude' => 9.0192,
        'longitude' => 38.7525,
        'geofence_radius_meters' => 200,
    ]);
}

afterEach(fn () => Carbon::setTestNow());

// ── Device clocks ──

it('reads a device time with no offset in the organisation zone, not UTC', function () {
    $tenant = createTenant(['timezone' => 'Africa/Addis_Ababa']);
    $device = Device::factory()->zkteco()->create(['tenant_id' => $tenant->id, 'branch_id' => Branch::factory()->create(['tenant_id' => $tenant->id])->id]);

    expect(DeviceTime::normalize('2026-10-13 08:01:00', $device))->toBe('2026-10-13T05:01:00+00:00')
        ->and(DeviceTime::normalize('2026-10-13T08:01:00Z', $device))->toBe('2026-10-13T08:01:00+00:00')
        ->and(DeviceTime::normalize('2026-10-13T08:01:00+03:00', $device))->toBe('2026-10-13T05:01:00+00:00')
        ->and(DeviceTime::normalize('not a time', $device))->toBeNull();

    $device->update(['timezone' => 'UTC']);
    expect(DeviceTime::normalize('2026-10-13 08:01:00', $device->fresh()))->toBe('2026-10-13T08:01:00+00:00');
});

it('stores a device punch at the moment it happened, and refuses one from the future', function () {
    Carbon::setTestNow('2026-10-13 06:00:00');
    $tenant = createTenant(['timezone' => 'Africa/Addis_Ababa']);
    $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
    $device = Device::factory()->zkteco()->create([
        'tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'serial_number' => 'ZK-INT',
        'webhook_token' => Device::generateWebhookToken(),
    ]);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'employee_code' => 'E-ZK']);
    app(CurrentTenant::class)->forget();

    test()->postJson(integrityUrl($tenant, "/devices/webhook/zkteco?token={$device->webhook_token}"), [
        'sn' => 'ZK-INT',
        'records' => [
            ['pin' => 'E-ZK', 'timestamp' => '2026-10-13 08:01:00', 'punch' => 0],
            ['pin' => 'E-ZK', 'timestamp' => '2026-10-13 12:00:00', 'punch' => 1],
        ],
    ])->assertOk();

    $record = AttendanceRecord::withoutGlobalScopes()->where('employee_id', $employee->id)->sole();
    // 08:01 in Addis is 05:01 UTC - not 08:01 UTC, three hours late.
    expect($record->check_in->utc()->format('H:i'))->toBe('05:01')
        // 12:00 Addis is 09:00 UTC, three hours after "now": refused.
        ->and($record->check_out)->toBeNull();
});

// ── QR ──

it('takes a QR code once per person per punch', function () {
    $tenant = createTenant();
    $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
    $qr = app(QrCodeService::class)->generate($branch, null, 30);
    [, $first] = integrityEmployee($tenant, ['branch_id' => $branch->id]);
    [, $second] = integrityEmployee($tenant, ['branch_id' => $branch->id]);

    $scan = fn (User $user, string $key) => test()->actingAs($user)->postJson(integrityUrl($tenant, '/attendance/qr'), [
        'idempotency_key' => $key, 'qr_token' => $qr['token'], 'type' => 'check_in',
    ]);

    $scan($first, 'qr-1')->assertCreated();
    // A retry of the same request is the same punch, not a second use.
    $scan($first, 'qr-1')->assertOk();
    // The same code again under a new key - a photo of it, used later.
    $scan($first, 'qr-2')->assertUnprocessable()->assertJsonPath('detail', __('attendance.qr_already_used'));
    $scan($second, 'qr-3')->assertCreated();
});

it('stops a QR code at the organisation limit', function () {
    $tenant = createTenant();
    AttendanceSetting::create([...AttendanceSetting::defaults(), 'tenant_id' => $tenant->id, 'qr_single_use_limit' => 1]);
    $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
    $qr = app(QrCodeService::class)->generate($branch, null, 30);
    [, $first] = integrityEmployee($tenant, ['branch_id' => $branch->id]);
    [, $second] = integrityEmployee($tenant, ['branch_id' => $branch->id]);

    test()->actingAs($first)->postJson(integrityUrl($tenant, '/attendance/qr'), ['idempotency_key' => 'l-1', 'qr_token' => $qr['token'], 'type' => 'check_in'])->assertCreated();
    test()->actingAs($second)->postJson(integrityUrl($tenant, '/attendance/qr'), ['idempotency_key' => 'l-2', 'qr_token' => $qr['token'], 'type' => 'check_in'])
        ->assertUnprocessable()->assertJsonPath('detail', __('attendance.qr_use_limit_reached'));
});

// ── Kiosk ──

it('locks one person at one kiosk after five wrong PINs, and says so', function () {
    $tenant = createTenant();
    AttendanceSetting::create([...AttendanceSetting::defaults(), 'tenant_id' => $tenant->id, 'kiosk_pin_required' => true]);
    $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
    Employee::factory()->create(['tenant_id' => $tenant->id, 'employee_code' => 'K-1', 'branch_id' => $branch->id, 'kiosk_pin' => Hash::make('4821')]);
    $session = KioskSession::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'status' => 'active']);
    app(CurrentTenant::class)->forget();

    $punch = function (string $pin, string $key) use ($tenant, $session) {
        app(CurrentTenant::class)->forget();

        return test()->postJson(integrityUrl($tenant, '/kiosk/check-in'), [
            'employee_code' => 'K-1', 'type' => 'check_in', 'pin' => $pin, 'idempotency_key' => $key,
        ], ['X-Kiosk-Token' => $session->token]);
    };

    foreach (range(1, 5) as $i) {
        $punch('0000', "bad-{$i}")->assertUnauthorized();
    }

    // The right PIN, now refused until the lock passes.
    $punch('4821', 'good')->assertStatus(429)->assertJsonPath('detail', __('kiosk.pin_locked'));
    $this->assertDatabaseHas('audit_log', ['action' => 'kiosk.pin_locked']);
});

it('flags a kiosk punch made at another branch', function () {
    $tenant = createTenant();
    $home = Branch::factory()->create(['tenant_id' => $tenant->id]);
    $elsewhere = Branch::factory()->create(['tenant_id' => $tenant->id]);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'employee_code' => 'K-2', 'branch_id' => $home->id]);
    $session = KioskSession::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $elsewhere->id, 'status' => 'active']);
    app(CurrentTenant::class)->forget();

    test()->postJson(integrityUrl($tenant, '/kiosk/check-in'), [
        'employee_code' => 'K-2', 'type' => 'check_in', 'idempotency_key' => 'k2',
    ], ['X-Kiosk-Token' => $session->token])->assertCreated();

    expect(AttendanceIntegrityFlag::withoutGlobalScopes()->where('employee_id', $employee->id)->pluck('type')->all())
        ->toContain('branch_mismatch');
});

// ── Offline ──

it('vouches for an offline punch inside its grant, and flags one without', function () {
    Carbon::setTestNow('2026-10-13 06:00:00');
    $tenant = createTenant();
    [$employee, $user] = integrityEmployee($tenant);

    $grant = test()->actingAs($user)->getJson(integrityUrl($tenant, '/attendance/offline-grant'))
        ->assertOk()
        ->json('grant');

    Carbon::setTestNow('2026-10-13 09:00:00');
    test()->actingAs($user)->postJson(integrityUrl($tenant, '/attendance/sync'), ['records' => [
        // Made two hours after the grant, while offline: covered.
        ['idempotency_key' => 'off-1', 'employee_public_id' => $employee->public_id, 'type' => 'check_in',
            'timestamp' => '2026-10-13T08:00:00Z', 'offline_token' => $grant],
    ]])->assertOk()->assertJsonPath('results.0.status', 'created');

    test()->actingAs($user)->postJson(integrityUrl($tenant, '/attendance/sync'), ['records' => [
        // The phone clock wound back before the grant was issued.
        ['idempotency_key' => 'off-2', 'employee_public_id' => $employee->public_id, 'type' => 'check_in',
            'timestamp' => '2026-10-12T08:00:00Z', 'offline_token' => $grant],
        // A legacy client's placeholder: no grant at all.
        ['idempotency_key' => 'off-3', 'employee_public_id' => $employee->public_id, 'type' => 'check_in',
            'timestamp' => '2026-10-11T08:00:00Z', 'offline_token' => 'offline-1760000000000'],
    ]])->assertOk();

    $types = fn (string $key) => AttendanceIntegrityFlag::withoutGlobalScopes()
        ->whereHas('record', fn ($q) => $q->withoutGlobalScopes()->where('idempotency_key', $key))
        ->pluck('type')->all();

    expect($types('off-1'))->not->toContain('unverified_offline')->not->toContain('clock_suspect')
        ->and($types('off-2'))->toContain('clock_suspect')
        ->and($types('off-3'))->toContain('unverified_offline');

    // The grant itself is never stored, only its fingerprint.
    expect(AttendanceRecord::withoutGlobalScopes()->where('idempotency_key', 'off-1')->value('offline_token'))
        ->toBe(OfflineGrant::fingerprint($grant));
});

it('treats another person\'s grant as no grant', function () {
    $tenant = createTenant();
    [$employee] = integrityEmployee($tenant);
    [$other] = integrityEmployee($tenant);

    $grant = app(OfflineGrant::class)->issue($other)['grant'];

    expect(app(OfflineGrant::class)->check($grant, $employee, Carbon::now('UTC')))->toBe('invalid')
        ->and(app(OfflineGrant::class)->check($grant, $other, Carbon::now('UTC')))->toBe('valid');
});

// ── Location ──

it('flags a web punch outside the branch, a fed-in location and an impossible journey', function () {
    $tenant = createTenant();
    $branch = geofencedBranch($tenant);
    [$employee, $user] = integrityEmployee($tenant, ['branch_id' => $branch->id]);

    // Yesterday, at exactly these coordinates.
    AttendanceRecord::factory()->create([
        'tenant_id' => $tenant->id, 'employee_id' => $employee->id,
        'date' => now()->subDay()->toDateString(), 'check_in' => now()->subDay(),
        'latitude' => 9.1, 'longitude' => 38.9,
    ]);
    // An hour ago, in Bahir Dar.
    AttendanceRecord::factory()->create([
        'tenant_id' => $tenant->id, 'employee_id' => $employee->id,
        'date' => now()->toDateString(), 'check_in' => now()->subHour(), 'check_out' => now()->subMinutes(50),
        'latitude' => 11.5936, 'longitude' => 37.3908,
    ]);

    test()->actingAs($user)->postJson(integrityUrl($tenant, '/attendance/check-in'), [
        'idempotency_key' => 'web-loc', 'latitude' => 9.1, 'longitude' => 38.9, 'accuracy' => 0,
    ])->assertSuccessful();

    $flags = AttendanceIntegrityFlag::withoutGlobalScopes()->where('employee_id', $employee->id)->get();

    expect($flags->pluck('type')->unique()->sort()->values()->all())
        ->toContain('outside_geofence', 'suspect_location', 'impossible_travel')
        ->and($flags->firstWhere('type', 'outside_geofence')?->severity)->toBe('high');
});

// ── Review ──

it('voids a rejected punch and tells the employee why', function () {
    Notification::fake();
    $tenant = createTenant();
    $branch = geofencedBranch($tenant);
    [$employee, $employeeUser] = integrityEmployee($tenant, ['branch_id' => $branch->id]);

    test()->actingAs($employeeUser)->postJson(integrityUrl($tenant, '/attendance/check-in'), [
        'idempotency_key' => 'far-away', 'latitude' => 8.5, 'longitude' => 39.3,
    ])->assertSuccessful();

    $flag = AttendanceIntegrityFlag::withoutGlobalScopes()->where('employee_id', $employee->id)->where('type', 'outside_geofence')->sole();

    // The employee cannot clear their own flag.
    test()->actingAs($employeeUser)->putJson(integrityUrl($tenant, "/attendance/integrity/{$flag->public_id}/review"), ['decision' => 'accept'])
        ->assertForbidden();

    $hr = createUser(['role' => UserRole::HR_ADMIN], $tenant);
    test()->actingAs($hr)->getJson(integrityUrl($tenant, '/attendance/integrity/summary'))
        ->assertOk()->assertJsonPath('open', 1);

    // A rejection needs a reason - it goes to the employee.
    test()->actingAs($hr)->putJson(integrityUrl($tenant, "/attendance/integrity/{$flag->public_id}/review"), ['decision' => 'reject'])
        ->assertUnprocessable()->assertJsonValidationErrors('note');

    test()->actingAs($hr)->putJson(integrityUrl($tenant, "/attendance/integrity/{$flag->public_id}/review"), [
        'decision' => 'reject', 'note' => 'You were 60 km from the branch.',
    ])->assertOk()->assertJsonPath('status', 'rejected');

    expect(AttendanceRecord::withoutGlobalScopes()->where('idempotency_key', 'far-away')->first()?->status)
        ->toBe(AttendanceStatus::VOIDED);
    Notification::assertSentTo($employeeUser, AttendancePunchRejectedNotification::class);
    $this->assertDatabaseHas('audit_log', ['action' => 'attendance.integrity_rejected']);

    test()->actingAs($hr)->putJson(integrityUrl($tenant, "/attendance/integrity/{$flag->public_id}/review"), ['decision' => 'accept'])
        ->assertUnprocessable();
});

it('lets HR accept a flag and keep the punch', function () {
    $tenant = createTenant();
    $branch = geofencedBranch($tenant);
    [$employee, $employeeUser] = integrityEmployee($tenant, ['branch_id' => $branch->id]);

    test()->actingAs($employeeUser)->postJson(integrityUrl($tenant, '/attendance/check-in'), [
        'idempotency_key' => 'site-visit', 'latitude' => 8.5, 'longitude' => 39.3,
    ])->assertSuccessful();
    $flag = AttendanceIntegrityFlag::withoutGlobalScopes()->where('employee_id', $employee->id)->firstOrFail();

    $hr = createUser(['role' => UserRole::HR_ADMIN], $tenant);
    test()->actingAs($hr)->putJson(integrityUrl($tenant, "/attendance/integrity/{$flag->public_id}/review"), [
        'decision' => 'accept', 'note' => 'Client site visit, approved by manager.',
    ])->assertOk()->assertJsonPath('status', 'accepted');

    expect(AttendanceRecord::withoutGlobalScopes()->where('idempotency_key', 'site-visit')->first()?->status)
        ->not->toBe(AttendanceStatus::VOIDED);
});
