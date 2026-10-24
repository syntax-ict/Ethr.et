<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSetting;
use App\Models\AttendanceSite;
use App\Models\AttendanceVisit;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\Shift;
use App\Models\Tenant;
use App\Services\Attendance\PhotoRetention;
use App\Support\PunchSignals;
use Carbon\Carbon;

/**
 * Field visits (stops logged during the day, never punches) and the signals
 * every punch now carries for risk scoring: the fix's details, a hashed
 * install id, the client clock, and the check-out's own IP.
 */
const FV_BRANCH = [9.0108, 38.7613];

afterEach(fn () => Carbon::setTestNow());

function fvUrl(Tenant $tenant, string $path): string
{
    return "http://{$tenant->subdomain}.ethr.test/api/v1{$path}";
}

/** @return array{0: Tenant, 1: Employee} */
function fvEmployee(array $settings = []): array
{
    Carbon::setTestNow(Carbon::parse('2026-10-12 08:20', 'Africa/Addis_Ababa')->utc());
    $tenant = createTenant();
    $branch = Branch::factory()->create(['tenant_id' => $tenant->id, 'latitude' => FV_BRANCH[0], 'longitude' => FV_BRANCH[1], 'geofence_radius_meters' => 150]);
    Shift::factory()->default()->create(['tenant_id' => $tenant->id, 'start_time' => '08:30', 'end_time' => '17:30']);
    AttendanceSetting::create(['tenant_id' => $tenant->id, ...AttendanceSetting::defaults(), ...$settings]);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id]);
    test()->actingAs(createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant));

    return [$tenant, $employee];
}

// ── Visits ──────────────────────────────────────────────────────────────────

test('a logged visit is kept with its site and never becomes a punch', function () {
    [$tenant, $employee] = fvEmployee();
    AttendanceSite::create(['tenant_id' => $tenant->id, 'branch_id' => $employee->branch_id, 'name' => 'Client A', 'latitude' => 9.05, 'longitude' => 38.76, 'radius_meters' => 200]);

    test()->postJson(fvUrl($tenant, '/attendance/visits'), [
        'idempotency_key' => 'fv-1', 'latitude' => 9.05, 'longitude' => 38.76, 'accuracy' => 12, 'note' => 'Delivered forms',
    ])->assertCreated()
        ->assertJsonPath('site_name', 'Client A')
        ->assertJsonPath('note', 'Delivered forms')
        ->assertJsonMissingPath('id');

    // A phone retrying after a lost response gets the same visit back.
    test()->postJson(fvUrl($tenant, '/attendance/visits'), [
        'idempotency_key' => 'fv-1', 'latitude' => 9.05, 'longitude' => 38.76,
    ])->assertOk();

    expect(AttendanceVisit::query()->count())->toBe(1)
        ->and(AttendanceRecord::query()->count())->toBe(0);
});

test('a visit logged offline is dated when it happened, inside the offline window only', function () {
    [$tenant] = fvEmployee();

    test()->postJson(fvUrl($tenant, '/attendance/visits'), [
        'idempotency_key' => 'fv-2', 'latitude' => 9.05, 'longitude' => 38.76,
        'occurred_at' => Carbon::now('UTC')->subHours(3)->toIso8601String(),
    ])->assertCreated();
    expect(AttendanceVisit::query()->sole()->occurred_at->diffInHours(Carbon::now('UTC'), true))->toBeGreaterThanOrEqual(2.9);

    test()->postJson(fvUrl($tenant, '/attendance/visits'), [
        'idempotency_key' => 'fv-3', 'latitude' => 9.05, 'longitude' => 38.76,
        'occurred_at' => Carbon::now('UTC')->subDays(30)->toIso8601String(),
    ])->assertStatus(422);
});

test('visits follow the phone method and photo consent', function () {
    [$tenant] = fvEmployee(['enabled_methods' => ['biometric']]);

    test()->postJson(fvUrl($tenant, '/attendance/visits'), [
        'idempotency_key' => 'fv-4', 'latitude' => 9.05, 'longitude' => 38.76,
    ])->assertStatus(422);

    AttendanceSetting::query()->update(['enabled_methods' => ['mobile']]);
    test()->postJson(fvUrl($tenant, '/attendance/visits'), [
        'idempotency_key' => 'fv-5', 'latitude' => 9.05, 'longitude' => 38.76,
        'photo' => fvJpeg(),
    ])->assertStatus(422)->assertJsonValidationErrors('photo');
});

function fvJpeg(): string
{
    $image = imagecreatetruecolor(16, 16);
    ob_start();
    imagejpeg($image);

    return 'data:image/jpeg;base64,'.base64_encode((string) ob_get_clean());
}

test('managers see their people\'s visits by day; employees cannot list everyone\'s', function () {
    [$tenant, $employee] = fvEmployee();
    test()->postJson(fvUrl($tenant, '/attendance/visits'), ['idempotency_key' => 'fv-6', 'latitude' => 9.05, 'longitude' => 38.76])->assertCreated();

    test()->getJson(fvUrl($tenant, '/attendance/visits/mine'))->assertOk()->assertJsonCount(1, 'data');
    test()->getJson(fvUrl($tenant, '/attendance/visits'))->assertForbidden();

    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    test()->getJson(fvUrl($tenant, '/attendance/visits?date=2026-10-12'))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.employee_public_id', $employee->public_id);
    test()->getJson(fvUrl($tenant, '/attendance/visits?date=2026-10-13'))->assertOk()->assertJsonCount(0, 'data');
});

test('old visit photos are deleted with the selfie retention period, the visit kept', function () {
    [$tenant, $employee] = fvEmployee();
    $visit = AttendanceVisit::create([
        'employee_id' => $employee->id, 'latitude' => 9.05, 'longitude' => 38.76,
        'occurred_at' => Carbon::now('UTC')->subDays(20), 'photo_path' => 'visits/old.jpg', 'idempotency_key' => 'fv-7',
    ]);
    $recent = AttendanceVisit::create([
        'employee_id' => $employee->id, 'latitude' => 9.05, 'longitude' => 38.76,
        'occurred_at' => Carbon::now('UTC')->subDay(), 'photo_path' => 'visits/new.jpg', 'idempotency_key' => 'fv-8',
    ]);

    expect(app(PhotoRetention::class)->deleteVisitPhotos(Carbon::now('UTC')))->toBe(1)
        ->and($visit->fresh()->photo_path)->toBeNull()
        ->and($recent->fresh()->photo_path)->toBe('visits/new.jpg');
});

// ── Punch signals ───────────────────────────────────────────────────────────

test('a phone punch keeps the fix details, a hashed install id and the clock gap', function () {
    [$tenant, $employee] = fvEmployee();

    test()->postJson(fvUrl($tenant, '/attendance/mobile/check-in'), [
        'idempotency_key' => 'fv-9', 'latitude' => FV_BRANCH[0], 'longitude' => FV_BRANCH[1], 'accuracy' => 8,
        'altitude' => 2355.4, 'speed' => 0, 'heading' => 90, 'fix_age_ms' => 1200,
        'device_id' => 'install-123', 'client_time' => Carbon::now('UTC')->subMinutes(7)->toIso8601String(),
    ])->assertCreated();

    $meta = AttendanceRecord::where('employee_id', $employee->id)->sole()->metadata;
    expect($meta['altitude'])->toBe(2355.4)
        ->and($meta['fix_age_ms'])->toBe(1200)
        ->and($meta['device'])->toBe(PunchSignals::deviceHash('install-123'))
        ->and($meta['device'])->not->toContain('install-123')
        ->and($meta['client_offset_seconds'])->toBe(420);
});

test('a check-out keeps its own IP and signals beside the check-in\'s', function () {
    [$tenant, $employee] = fvEmployee();
    test()->postJson(fvUrl($tenant, '/attendance/check-in'), ['idempotency_key' => 'fv-10', 'device_id' => 'phone-a'])->assertSuccessful();

    Carbon::setTestNow(Carbon::parse('2026-10-12 17:35', 'Africa/Addis_Ababa')->utc());
    test()->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
        ->postJson(fvUrl($tenant, '/attendance/check-out'), ['idempotency_key' => 'fv-11', 'device_id' => 'phone-b'])
        ->assertSuccessful();

    $meta = AttendanceRecord::where('employee_id', $employee->id)->sole()->metadata;
    expect($meta['device'])->toBe(PunchSignals::deviceHash('phone-a'))
        ->and($meta['checkout_ip_address'])->toBe('203.0.113.9')
        ->and($meta['checkout_signals']['device'])->toBe(PunchSignals::deviceHash('phone-b'));
});
