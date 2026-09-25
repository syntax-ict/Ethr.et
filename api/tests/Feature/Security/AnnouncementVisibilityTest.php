<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Announcement;
use App\Models\Employee;
use Illuminate\Support\Str;

/**
 * `GET /announcements` has always filtered to published, unexpired rows.
 * `GET /announcements/{announcement}` did not: route-model binding resolves a
 * public id without either scope, so an employee holding the id of a draft
 * could read an announcement HR had explicitly not published.
 *
 * These pin the single-read against the list. The asymmetry is the defect —
 * a test that only checks the draft case would pass again the moment someone
 * reintroduced it on the expiry half.
 */
function announcementTenantAndEmployee(): array
{
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $user = createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant);
    test()->actingAs($user);

    return [$tenant, $user];
}

test('an employee can read a published announcement', function () {
    [$tenant, $user] = announcementTenantAndEmployee();

    $announcement = Announcement::factory()->create([
        'tenant_id' => $tenant->id,
        'published_by' => $user->id,
    ]);

    test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/announcements/{$announcement->public_id}")
        ->assertOk()
        ->assertJsonPath('public_id', $announcement->public_id);
});

test('an employee cannot read an announcement the list would not show', function (array $state) {
    [$tenant, $user] = announcementTenantAndEmployee();

    $announcement = Announcement::factory()->create([
        'tenant_id' => $tenant->id,
        'published_by' => $user->id,
        ...$state,
    ]);

    // The list is the contract this read has to match.
    test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/announcements")
        ->assertOk()
        ->assertJsonCount(0, 'data');

    test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/announcements/{$announcement->public_id}")
        ->assertNotFound();
})->with([
    'draft' => [['published_at' => null]],
    'scheduled for later' => [['published_at' => now()->addDay()]],
    'expired' => [['expires_at' => now()->subDay()]],
]);

test('the hidden-announcement response is identical to a missing one', function () {
    [$tenant, $user] = announcementTenantAndEmployee();

    $draft = Announcement::factory()->draft()->create([
        'tenant_id' => $tenant->id,
        'published_by' => $user->id,
    ]);

    $absentId = (string) Str::ulid();

    $hidden = test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/announcements/{$draft->public_id}");
    $missing = test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/announcements/{$absentId}");

    $hidden->assertNotFound();
    $missing->assertNotFound();

    // Each body echoes the id that was asked for, and the two requests cannot
    // ask for the same one — so the comparison is of everything *except* that
    // id. The id is not information the response disclosed: the caller supplied
    // it. Anything else differing between the two would be, which is exactly
    // what withholding the draft is for.
    $withoutId = fn (?array $body, string $id): array => (array) json_decode(
        str_replace($id, '{id}', (string) json_encode($body)),
        true,
    );

    expect($withoutId($hidden->json(), $draft->public_id))
        ->toBe($withoutId($missing->json(), $absentId));
});

test('a manager can read a draft announcement', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    $draft = Announcement::factory()->draft()->create([
        'tenant_id' => $tenant->id,
        'published_by' => $user->id,
    ]);

    test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/announcements/{$draft->public_id}")
        ->assertOk()
        ->assertJsonPath('public_id', $draft->public_id);
});
