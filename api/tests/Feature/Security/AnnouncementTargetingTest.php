<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Announcement;
use App\Models\Branch;
use App\Models\Department;
use App\Models\Employee;

/**
 * Announcement targeting was honoured by NotifyAnnouncementAudienceJob and by
 * nothing else. The list and the single read showed a department-only
 * announcement to the whole tenant, and the request accepted any string as
 * `target_id` while the model cast it to an integer — so the only identifier
 * the API hands out, a public_id, could not target anything. `role` targeting
 * could never work: the column is a bigint.
 */
function targetingTenant(): array
{
    $tenant = createTenant();
    $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
    $sales = Department::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id]);
    $finance = Department::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id]);

    return [$tenant, $branch, $sales, $finance];
}

function employeeIn(object $tenant, Department $department, Branch $branch): void
{
    $employee = Employee::factory()->create([
        'tenant_id' => $tenant->id,
        'department_id' => $department->id,
        'branch_id' => $branch->id,
    ]);
    test()->actingAs(createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant));
}

function announcementFor(object $tenant, string $type, ?int $targetId): Announcement
{
    return Announcement::factory()->create([
        'tenant_id' => $tenant->id,
        'target_type' => $type,
        'target_id' => $targetId,
        'published_at' => now()->subMinute(),
    ]);
}

test('a manager targets a department by its public id', function () {
    // The second department, on purpose: (int) "01K…" is 1, and so is the first
    // department's id in a fresh database — the old cast passed by coincidence.
    [$tenant, , , $finance] = targetingTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/announcements", [
        'title' => 'Sales kick-off',
        'body' => 'Monday 9:00.',
        'target_type' => 'department',
        'target_id' => $finance->public_id,
    ])->assertCreated();

    $stored = Announcement::where('tenant_id', $tenant->id)->where('title', 'Sales kick-off')->firstOrFail();
    expect($stored->target_type)->toBe('department')
        ->and($stored->target_id)->toBe($finance->id);
});

test('a department from another tenant is refused like one that does not exist', function () {
    [$tenant] = targetingTenant();
    [$other, , $otherDepartment] = targetingTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    $foreign = test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/announcements", [
        'title' => 'x', 'body' => 'y', 'target_type' => 'department', 'target_id' => $otherDepartment->public_id,
    ])->assertStatus(422)->json('errors.target_id');

    $missing = test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/announcements", [
        'title' => 'x', 'body' => 'y', 'target_type' => 'department', 'target_id' => '01HZZZZZZZZZZZZZZZZZZZZZZZ',
    ])->assertStatus(422)->json('errors.target_id');

    expect($foreign)->toBe($missing);
});

test('targeting needs a target id, and role targeting is not offered', function () {
    [$tenant] = targetingTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/announcements", [
        'title' => 'x', 'body' => 'y', 'target_type' => 'branch',
    ])->assertStatus(422)->assertJsonValidationErrors('target_id');

    test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/announcements", [
        'title' => 'x', 'body' => 'y', 'target_type' => 'role', 'target_id' => 'hr_admin',
    ])->assertStatus(422)->assertJsonValidationErrors('target_type');
});

test('an employee sees tenant-wide and own-department announcements only', function () {
    [$tenant, $branch, $sales, $finance] = targetingTenant();
    $all = announcementFor($tenant, 'all', null);
    $own = announcementFor($tenant, 'department', $sales->id);
    $other = announcementFor($tenant, 'department', $finance->id);
    employeeIn($tenant, $sales, $branch);

    $listed = test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/announcements")
        ->assertOk()->json('data.*.public_id');
    expect($listed)->toEqualCanonicalizing([$all->public_id, $own->public_id]);

    test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/announcements/{$other->public_id}")
        ->assertNotFound();
});

test('an employee sees announcements for their own branch only', function () {
    [$tenant, $branch, $sales] = targetingTenant();
    $otherBranch = Branch::factory()->create(['tenant_id' => $tenant->id]);
    $own = announcementFor($tenant, 'branch', $branch->id);
    announcementFor($tenant, 'branch', $otherBranch->id);
    employeeIn($tenant, $sales, $branch);

    test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/announcements")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.public_id', $own->public_id);
});

test('a manager sees every targeted announcement', function () {
    [$tenant, , $sales, $finance] = targetingTenant();
    announcementFor($tenant, 'department', $sales->id);
    announcementFor($tenant, 'department', $finance->id);
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/announcements")
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

test('an update can retarget an announcement to a branch', function () {
    [$tenant] = targetingTenant();
    $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
    $announcement = announcementFor($tenant, 'all', null);
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    test()->putJson("http://{$tenant->subdomain}.ethr.test/api/v1/announcements/{$announcement->public_id}", [
        'target_type' => 'branch',
        'target_id' => $branch->public_id,
    ])->assertOk();

    expect($announcement->fresh()->only(['target_type', 'target_id']))
        ->toBe(['target_type' => 'branch', 'target_id' => $branch->id]);
});
