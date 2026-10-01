<?php

declare(strict_types=1);

use App\Enums\AttendanceSource;
use App\Enums\EmployeeStatus;
use App\Enums\TenantStatus;
use App\Enums\UserRole;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSetting;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\KioskSession;
use App\Models\Tenant;
use App\Services\Attendance\AttendanceEngine;
use App\Services\Attendance\AttendanceInput;
use App\Services\CurrentTenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * KioskSessionTest pins the kiosk's happy paths. These pin what a kiosk token
 * — a long-lived credential sitting on a tablet in a lobby — can and cannot
 * reach, what the admin endpoints do with another tenant's ids, and the
 * authenticated /attendance/kiosk endpoint, which had no test at all.
 */

/** @return array{tenant: Tenant, branch: Branch, session: KioskSession} */
function kioskSecuritySetup(array $tenantAttributes = []): array
{
    $tenant = createTenant($tenantAttributes);
    $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
    $session = KioskSession::factory()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'status' => 'active',
    ]);

    return compact('tenant', 'branch', 'session');
}

function kioskSecurityCheckIn(string $host, string $token, string $employeeCode, string $type = 'check_in')
{
    return test()->postJson("http://{$host}/api/v1/kiosk/check-in", [
        'employee_code' => $employeeCode,
        'type' => $type,
        'idempotency_key' => 'kiosk-sec-'.Str::uuid(),
    ], ['X-Kiosk-Token' => $token]);
}

function kioskSecurityHost(Tenant $tenant): string
{
    return "{$tenant->subdomain}.ethr.test";
}

// ── The public kiosk endpoints ──────────────────────────────────────────────

it('records a kiosk punch against the kiosk\'s own tenant and branch', function () {
    ['tenant' => $tenant, 'branch' => $branch, 'session' => $session] = kioskSecuritySetup();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'employee_code' => 'EMP-K1']);

    kioskSecurityCheckIn(kioskSecurityHost($tenant), $session->token, 'EMP-K1')
        ->assertCreated()
        ->assertJsonPath('source', 'kiosk')
        ->assertJsonPath('was_duplicate', false);

    $record = AttendanceRecord::where('employee_id', $employee->id)->firstOrFail();
    expect($record->tenant_id)->toBe($tenant->id)
        ->and($record->metadata['kiosk_branch_id'])->toBe($branch->id)
        ->and($session->fresh()->last_activity_at)->not->toBeNull();
});

it('treats a retried punch with the same idempotency key as a duplicate, not a second punch', function () {
    ['tenant' => $tenant, 'session' => $session] = kioskSecuritySetup();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'employee_code' => 'EMP-K2']);
    $payload = ['employee_code' => 'EMP-K2', 'type' => 'check_in', 'idempotency_key' => 'tablet-retry-1'];
    $url = 'http://'.kioskSecurityHost($tenant).'/api/v1/kiosk/check-in';

    $this->postJson($url, $payload, ['X-Kiosk-Token' => $session->token])->assertCreated();
    $this->postJson($url, $payload, ['X-Kiosk-Token' => $session->token])
        ->assertOk()
        ->assertJsonPath('was_duplicate', true);

    expect(AttendanceRecord::where('employee_id', $employee->id)->count())->toBe(1);
});

it('refuses a kiosk token on another tenant\'s host', function () {
    ['session' => $mySession] = kioskSecuritySetup();
    ['tenant' => $other] = kioskSecuritySetup();
    Employee::factory()->create(['tenant_id' => $other->id, 'employee_code' => 'EMP-OTHER']);

    kioskSecurityCheckIn(kioskSecurityHost($other), $mySession->token, 'EMP-OTHER')->assertUnauthorized();
    $this->postJson('http://'.kioskSecurityHost($other).'/api/v1/kiosk/authenticate', ['token' => $mySession->token])
        ->assertUnauthorized();

    expect(DB::table('attendance_records')->where('tenant_id', $other->id)->exists())->toBeFalse();
});

it('refuses every kiosk token on the apex host, where no tenant is resolved', function () {
    // KioskSession is BelongsToTenant: with no tenant resolved the scope is
    // fail-closed, so a token presented outside a tenant host finds nothing.
    ['session' => $session] = kioskSecuritySetup();
    app(CurrentTenant::class)->forget();

    $this->postJson('/api/v1/kiosk/authenticate', ['token' => $session->token])->assertUnauthorized();
});

it('answers an employee code from another tenant exactly as it answers an unknown one', function () {
    ['tenant' => $tenant, 'session' => $session] = kioskSecuritySetup();
    $other = createTenant();
    Employee::factory()->create(['tenant_id' => $other->id, 'employee_code' => 'EMP-ELSEWHERE']);
    app(CurrentTenant::class)->set($tenant);

    $foreign = kioskSecurityCheckIn(kioskSecurityHost($tenant), $session->token, 'EMP-ELSEWHERE');
    $unknown = kioskSecurityCheckIn(kioskSecurityHost($tenant), $session->token, 'EMP-NOBODY');

    $foreign->assertNotFound();
    expect($foreign->json())->toBe($unknown->json());
    expect(DB::table('attendance_records')->count())->toBe(0);
});

it('refuses a deactivated kiosk at check-in, not only at authenticate', function () {
    ['tenant' => $tenant, 'session' => $session] = kioskSecuritySetup();
    Employee::factory()->create(['tenant_id' => $tenant->id, 'employee_code' => 'EMP-K3']);
    $session->update(['status' => 'inactive']);

    kioskSecurityCheckIn(kioskSecurityHost($tenant), $session->token, 'EMP-K3')->assertUnauthorized();
});

it('stops accepting the old token the moment it is regenerated', function () {
    ['tenant' => $tenant, 'session' => $session] = kioskSecuritySetup();
    Employee::factory()->create(['tenant_id' => $tenant->id, 'employee_code' => 'EMP-K4']);
    $oldToken = $session->token;
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    $newToken = $this->postJson('http://'.kioskSecurityHost($tenant)."/api/v1/kiosk-sessions/{$session->public_id}/regenerate-token")
        ->assertOk()
        ->json('token');

    expect($newToken)->not->toBe($oldToken);
    kioskSecurityCheckIn(kioskSecurityHost($tenant), $oldToken, 'EMP-K4')->assertUnauthorized();
    kioskSecurityCheckIn(kioskSecurityHost($tenant), $newToken, 'EMP-K4')->assertCreated();
});

it('refuses kiosk punches for a suspended tenant', function () {
    ['tenant' => $tenant, 'session' => $session] = kioskSecuritySetup();
    Employee::factory()->create(['tenant_id' => $tenant->id, 'employee_code' => 'EMP-K5']);
    $tenant->update(['status' => TenantStatus::SUSPENDED]);

    kioskSecurityCheckIn(kioskSecurityHost($tenant), $session->token, 'EMP-K5')->assertForbidden();

    expect(DB::table('attendance_records')->where('tenant_id', $tenant->id)->exists())->toBeFalse();
});

it('refuses a punch for an employee who has left', function () {
    // FIXED 2026-10-01 (N15). Was: nothing between KioskCheckInController and AttendanceEngine::record()
    // looks at Employee.status, so a terminated employee's code — printed on a
    // badge they may still have — keeps creating attendance records, which
    // feed payroll and overtime.
    ['tenant' => $tenant, 'session' => $session] = kioskSecuritySetup();
    $leaver = Employee::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_code' => 'EMP-LEFT',
        'status' => EmployeeStatus::TERMINATED,
    ]);

    $response = kioskSecurityCheckIn(kioskSecurityHost($tenant), $session->token, 'EMP-LEFT');

    expect($response->status())->toBeIn([403, 404, 422]);
    expect(AttendanceRecord::where('employee_id', $leaver->id)->exists())->toBeFalse();
});

// ── The admin endpoints ─────────────────────────────────────────────────────

it('answers another tenant\'s kiosk id with 404 on every admin route and changes nothing', function () {
    ['tenant' => $tenant] = kioskSecuritySetup();
    ['session' => $theirs] = kioskSecuritySetup();
    $theirToken = $theirs->token;
    app(CurrentTenant::class)->set($tenant);
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    $base = 'http://'.kioskSecurityHost($tenant)."/api/v1/kiosk-sessions/{$theirs->public_id}";

    $this->getJson($base)->assertNotFound();
    $this->postJson("{$base}/deactivate")->assertNotFound();
    $this->postJson("{$base}/regenerate-token")->assertNotFound();
    $this->deleteJson($base)->assertNotFound();

    $row = DB::table('kiosk_sessions')->where('id', $theirs->id)->first();
    expect($row)->not->toBeNull()
        ->and($row->status)->toBe('active')
        ->and($row->token)->toBe($theirToken);
});

it('does not let an employee manage kiosks', function () {
    ['tenant' => $tenant, 'branch' => $branch, 'session' => $session] = kioskSecuritySetup();
    actingAsUser(['role' => UserRole::EMPLOYEE], $tenant);
    $base = 'http://'.kioskSecurityHost($tenant).'/api/v1/kiosk-sessions';

    $this->postJson($base, ['name' => 'Rogue', 'branch_public_id' => $branch->public_id, 'admin_pin' => '1234'])->assertForbidden();
    $this->postJson("{$base}/{$session->public_id}/regenerate-token")->assertForbidden();
    $this->postJson("{$base}/{$session->public_id}/deactivate")->assertForbidden();

    expect($session->fresh()->status)->toBe('active');
});

it('shows a kiosk token once, at registration, and never in a listing', function () {
    ['tenant' => $tenant, 'branch' => $branch] = kioskSecuritySetup();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    $base = 'http://'.kioskSecurityHost($tenant).'/api/v1/kiosk-sessions';

    $created = $this->postJson($base, ['name' => 'Lobby', 'branch_public_id' => $branch->public_id, 'admin_pin' => '4321'])
        ->assertCreated();
    expect($created->json('token'))->toBeString();

    $listing = $this->getJson($base)->assertOk();
    foreach ($listing->json('data') as $row) {
        expect($row)->not->toHaveKey('token')
            ->and($row)->not->toHaveKey('admin_pin');
    }

    $this->getJson("{$base}/{$created->json('public_id')}")->assertOk()->assertJsonMissingPath('token');
});

it('answers another tenant\'s branch at registration exactly as it answers a nonexistent one', function () {
    // RegisterKioskRequest validates `exists:branches,public_id` unscoped
    // (RegisterKioskRequest.php:21), then the controller's scoped lookup
    // firstOrFail()s. Nonexistent: 422. Another tenant's real branch: 404.
    ['tenant' => $tenant] = kioskSecuritySetup();
    ['branch' => $theirBranch] = kioskSecuritySetup();
    app(CurrentTenant::class)->set($tenant);
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    $base = 'http://'.kioskSecurityHost($tenant).'/api/v1/kiosk-sessions';

    $nonexistent = $this->postJson($base, ['name' => 'X', 'branch_public_id' => '01JNOTAREALBRANCH000000000', 'admin_pin' => '1234']);
    $crossTenant = $this->postJson($base, ['name' => 'X', 'branch_public_id' => $theirBranch->public_id, 'admin_pin' => '1234']);

    expect($crossTenant->status())->toBe($nonexistent->status());
});

// ── POST /attendance/kiosk (authenticated) ──────────────────────────────────

it('lets a user with the kiosk permission record a punch for an employee of their own tenant only', function () {
    $tenant = createTenant();
    $hr = actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    Employee::factory()->create(['tenant_id' => $tenant->id, 'employee_code' => 'EMP-A1']);
    $other = createTenant();
    Employee::factory()->create(['tenant_id' => $other->id, 'employee_code' => 'EMP-B1']);
    app(CurrentTenant::class)->set($tenant);
    $url = 'http://'.kioskSecurityHost($tenant).'/api/v1/attendance/kiosk';

    $this->postJson($url, ['employee_code' => 'EMP-A1', 'type' => 'check_in', 'idempotency_key' => (string) Str::uuid()])
        ->assertCreated();
    $this->postJson($url, ['employee_code' => 'EMP-B1', 'type' => 'check_in', 'idempotency_key' => (string) Str::uuid()])
        ->assertNotFound();

    expect(DB::table('attendance_records')->where('tenant_id', $other->id)->exists())->toBeFalse();
});

it('does not let an ordinary employee clock a colleague in', function () {
    // FIXED 2026-10-01 (N15). Was: KioskAttendanceController::store gated on `attendance.checkIn`, which
    // PermissionSeeder grants to EVERY role (it is how an employee punches
    // themselves in), then records for whatever employee_code the body names.
    // So any logged-in employee can create check-ins and check-outs for any
    // colleague — buddy punching, straight into payroll — with no kiosk
    // session, PIN, or kiosk-method setting involved.
    $tenant = createTenant();
    $me = Employee::factory()->create(['tenant_id' => $tenant->id, 'employee_code' => 'EMP-ME']);
    actingAsUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $me->id], $tenant);
    $colleague = Employee::factory()->create(['tenant_id' => $tenant->id, 'employee_code' => 'EMP-FRIEND']);

    $response = $this->postJson('http://'.kioskSecurityHost($tenant).'/api/v1/attendance/kiosk', [
        'employee_code' => 'EMP-FRIEND',
        'type' => 'check_in',
        'idempotency_key' => (string) Str::uuid(),
    ]);

    expect($response->status())->toBe(403);
    expect(AttendanceRecord::where('employee_id', $colleague->id)->exists())->toBeFalse();
});

it('still lets an employee punch themselves, and HR punch anyone', function () {
    $tenant = createTenant();
    $me = Employee::factory()->create(['tenant_id' => $tenant->id, 'employee_code' => 'EMP-SELF']);
    $colleague = Employee::factory()->create(['tenant_id' => $tenant->id, 'employee_code' => 'EMP-OTHER']);
    $url = 'http://'.kioskSecurityHost($tenant).'/api/v1/attendance/kiosk';

    actingAsUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $me->id], $tenant);
    $this->postJson($url, ['employee_code' => 'EMP-SELF', 'type' => 'check_in', 'idempotency_key' => (string) Str::uuid()])
        ->assertCreated();

    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    $this->postJson($url, ['employee_code' => 'EMP-OTHER', 'type' => 'check_in', 'idempotency_key' => (string) Str::uuid()])
        ->assertCreated();

    expect(AttendanceRecord::where('employee_id', $colleague->id)->exists())->toBeTrue();
});

it('still counts a leaver\'s punch from on or before their last day', function () {
    // A device backlog can deliver the last day's punches after HR has marked
    // the employee terminated; those happened while they still worked here.
    ['tenant' => $tenant] = kioskSecuritySetup();
    $leaver = Employee::factory()->create([
        'tenant_id' => $tenant->id,
        'status' => EmployeeStatus::TERMINATED,
        'termination_date' => '2026-09-30',
    ]);

    $result = app(AttendanceEngine::class)->record(new AttendanceInput(
        employeeId: $leaver->id,
        tenantId: $tenant->id,
        source: AttendanceSource::BIOMETRIC,
        type: 'check_in',
        idempotencyKey: (string) Str::uuid(),
        occurredAt: '2026-09-30T08:30:00+03:00',
    ));

    expect($result->record->employee_id)->toBe($leaver->id);
});

it('answers a punch for a method the tenant turned off with 422, not 500', function () {
    $tenant = createTenant();
    $me = Employee::factory()->create(['tenant_id' => $tenant->id]);
    actingAsUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $me->id], $tenant);
    AttendanceSetting::withoutGlobalScope('tenant')->updateOrCreate(
        ['tenant_id' => $tenant->id],
        ['enabled_methods' => ['kiosk']],
    );

    $this->postJson('http://'.kioskSecurityHost($tenant).'/api/v1/attendance/check-in', [
        'idempotency_key' => (string) Str::uuid(),
    ])->assertStatus(422);
});
