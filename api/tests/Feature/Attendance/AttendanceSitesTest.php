<?php

declare(strict_types=1);

use App\Enums\AttendanceMode;
use App\Enums\UserRole;
use App\Models\AttendanceIntegrityFlag;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSetting;
use App\Models\AttendanceSite;
use App\Models\AttendanceSiteAssignment;
use App\Models\Branch;
use App\Models\Department;
use App\Models\Employee;
use App\Models\PlatformSetting;
use App\Models\Shift;
use App\Models\Tenant;
use App\Services\CurrentTenant;
use Carbon\Carbon;
use Illuminate\Testing\TestResponse;

/**
 * Work sites: places besides the branch where people may punch, and an
 * accuracy-aware inside check (AttendancePlaces). Until 2026-10-24 a branch
 * had one circle, so a project site or a client's office could not be a place
 * to punch at all, and a fix "give or take 30 m" just outside the circle was
 * refused.
 */
const WS_BRANCH = [9.0108, 38.7613];
// About 2.2 km north of the branch.
const WS_SITE = [9.0308, 38.7613];

afterEach(fn () => Carbon::setTestNow());

function wsUrl(Tenant $tenant, string $path): string
{
    return "http://{$tenant->subdomain}.ethr.test/api/v1{$path}";
}

/** @return array{0: Tenant, 1: Branch} */
function wsOrganisation(): array
{
    Carbon::setTestNow(Carbon::parse('2026-10-12 08:20', 'Africa/Addis_Ababa')->utc());
    $tenant = createTenant();
    $branch = Branch::factory()->create([
        'tenant_id' => $tenant->id, 'latitude' => WS_BRANCH[0], 'longitude' => WS_BRANCH[1], 'geofence_radius_meters' => 100,
    ]);
    Shift::factory()->default()->create(['tenant_id' => $tenant->id, 'start_time' => '08:30', 'end_time' => '17:30']);
    AttendanceSetting::create(['tenant_id' => $tenant->id, ...AttendanceSetting::defaults(), 'geofence_required' => true]);

    return [$tenant, $branch];
}

function wsSite(Tenant $tenant, array $attributes = []): AttendanceSite
{
    return AttendanceSite::create([
        'tenant_id' => $tenant->id,
        'name' => 'Project Bole',
        'latitude' => WS_SITE[0],
        'longitude' => WS_SITE[1],
        'radius_meters' => 150,
        'is_active' => true,
        ...$attributes,
    ]);
}

function wsActAs(Tenant $tenant, Employee $employee): void
{
    test()->actingAs(createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant));
}

function wsPunch(Tenant $tenant, string $key, float $lat, float $lng, ?float $accuracy = null): TestResponse
{
    return test()->postJson(wsUrl($tenant, '/attendance/mobile/check-in'), array_filter([
        'idempotency_key' => $key, 'latitude' => $lat, 'longitude' => $lng, 'accuracy' => $accuracy,
    ], fn ($v) => $v !== null));
}

test('a person assigned to a site may punch there under a required geofence', function () {
    [$tenant, $branch] = wsOrganisation();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id]);
    $site = wsSite($tenant);
    AttendanceSiteAssignment::create(['tenant_id' => $tenant->id, 'attendance_site_id' => $site->id, 'assignable_type' => Employee::class, 'assignable_id' => $employee->id]);
    wsActAs($tenant, $employee);

    wsPunch($tenant, 'ws-1', WS_SITE[0], WS_SITE[1])->assertCreated();

    $record = AttendanceRecord::where('employee_id', $employee->id)->sole();
    expect($record->geofence_verified)->toBeTrue()
        ->and(AttendanceIntegrityFlag::where('attendance_record_id', $record->id)->pluck('type')->all())->not->toContain('outside_geofence');
});

test("a site at the person's branch, or assigned to their department, counts too", function () {
    [$tenant, $branch] = wsOrganisation();
    $department = Department::factory()->create(['tenant_id' => $tenant->id]);
    wsSite($tenant, ['branch_id' => $branch->id, 'name' => 'Annex']);
    $atBranch = Employee::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id]);
    wsActAs($tenant, $atBranch);
    wsPunch($tenant, 'ws-2', WS_SITE[0], WS_SITE[1])->assertCreated();

    $otherBranch = Branch::factory()->create(['tenant_id' => $tenant->id, 'latitude' => 8.9, 'longitude' => 38.7, 'geofence_radius_meters' => 100]);
    $site = wsSite($tenant, ['name' => 'Field depot', 'latitude' => 9.0508]);
    AttendanceSiteAssignment::create(['tenant_id' => $tenant->id, 'attendance_site_id' => $site->id, 'assignable_type' => Department::class, 'assignable_id' => $department->id]);
    $inDepartment = Employee::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $otherBranch->id, 'department_id' => $department->id]);
    wsActAs($tenant, $inDepartment);
    wsPunch($tenant, 'ws-3', 9.0508, WS_SITE[1])->assertCreated();
});

test('outside the branch and every site is still refused for someone at the office', function () {
    [$tenant, $branch] = wsOrganisation();
    wsSite($tenant);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id]);
    wsActAs($tenant, $employee);

    // The site is not theirs: no assignment, another branch.
    wsPunch($tenant, 'ws-4', WS_SITE[0], WS_SITE[1])->assertStatus(422);
});

test("a fix's reported accuracy is given the benefit of the doubt, up to 50 m", function () {
    [$tenant, $branch] = wsOrganisation();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id]);
    wsActAs($tenant, $employee);
    // 0.0011 degrees of latitude is about 122 m: 22 m outside a 100 m circle.
    $lat = WS_BRANCH[0] + 0.0011;

    wsPunch($tenant, 'ws-5', $lat, WS_BRANCH[1], 5)->assertStatus(422);
    wsPunch($tenant, 'ws-6', $lat, WS_BRANCH[1], 30)->assertCreated();
});

test('a field worker outside every place is flagged with the nearest one named', function () {
    [$tenant, $branch] = wsOrganisation();
    wsSite($tenant, ['branch_id' => $branch->id]);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'attendance_mode' => AttendanceMode::FIELD]);
    wsActAs($tenant, $employee);

    wsPunch($tenant, 'ws-7', 9.0600, WS_SITE[1])->assertCreated();

    $flag = AttendanceIntegrityFlag::where('type', 'outside_geofence')->sole();
    expect($flag->details['place'])->toBe('Project Bole');
});

test('the punch assistant lists every place the person may punch', function () {
    [$tenant, $branch] = wsOrganisation();
    wsSite($tenant, ['branch_id' => $branch->id]);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id]);
    wsActAs($tenant, $employee);

    test()->getJson(wsUrl($tenant, '/attendance/my-shift'))
        ->assertOk()
        ->assertJsonCount(2, 'policy.places')
        ->assertJsonPath('policy.places.0.kind', 'branch')
        ->assertJsonPath('policy.places.1.name', 'Project Bole');
});

// ── API ─────────────────────────────────────────────────────────────────────

test('an admin creates, edits and removes a site with its people and departments', function () {
    [$tenant, $branch] = wsOrganisation();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $department = Department::factory()->create(['tenant_id' => $tenant->id]);
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    $id = test()->postJson(wsUrl($tenant, '/attendance/sites'), [
        'name' => 'Farm 3',
        'branch_public_id' => $branch->public_id,
        'latitude' => 9.1,
        'longitude' => 38.8,
        'radius_meters' => 300,
        'employee_public_ids' => [$employee->public_id],
        'department_public_ids' => [$department->public_id],
    ])->assertCreated()
        ->assertJsonPath('branch_public_id', $branch->public_id)
        ->assertJsonPath('employee_public_ids', [$employee->public_id])
        ->assertJsonMissingPath('id')
        ->json('public_id');

    test()->putJson(wsUrl($tenant, "/attendance/sites/{$id}"), ['radius_meters' => 80, 'employee_public_ids' => []])
        ->assertOk()
        ->assertJsonPath('radius_meters', 80)
        ->assertJsonPath('employee_public_ids', [])
        ->assertJsonPath('department_public_ids', [$department->public_id]);

    test()->getJson(wsUrl($tenant, '/attendance/sites'))->assertOk()->assertJsonCount(1);
    test()->deleteJson(wsUrl($tenant, "/attendance/sites/{$id}"))->assertNoContent();
    expect(AttendanceSite::query()->count())->toBe(0)
        ->and(AttendanceSite::withTrashed()->count())->toBe(1);
});

test("another organisation's people cannot be put on a site", function () {
    [$tenant] = wsOrganisation();
    $stranger = Employee::factory()->create(['tenant_id' => createTenant()->id]);
    app(CurrentTenant::class)->set($tenant);
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    test()->postJson(wsUrl($tenant, '/attendance/sites'), [
        'name' => 'Site', 'latitude' => 9.1, 'longitude' => 38.8,
        'employee_public_ids' => [$stranger->public_id],
    ])->assertStatus(422)->assertJsonValidationErrors('employee_public_ids');
});

test('an employee cannot manage sites', function () {
    [$tenant, $branch] = wsOrganisation();
    wsActAs($tenant, Employee::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id]));

    test()->getJson(wsUrl($tenant, '/attendance/sites'))->assertForbidden();
    test()->getJson(wsUrl($tenant, '/attendance/map-config'))->assertForbidden();
});

test('the map pickers get the platform Google key only when there is one', function () {
    [$tenant] = wsOrganisation();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    test()->getJson(wsUrl($tenant, '/attendance/map-config'))->assertOk()->assertJsonPath('google_maps_key', null);

    PlatformSetting::query()->updateOrCreate([], ['google_maps_key' => 'AIzaSyDexampleexampleexampleexample0123']);
    test()->getJson(wsUrl($tenant, '/attendance/map-config'))
        ->assertOk()
        ->assertJsonPath('google_maps_key', 'AIzaSyDexampleexampleexampleexample0123');
});
