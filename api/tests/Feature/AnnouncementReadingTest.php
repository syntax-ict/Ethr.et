<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Announcement;
use App\Models\Employee;
use App\Models\Tenant;
use App\Notifications\AnnouncementNotification;
use Illuminate\Support\Facades\DB;

/**
 * Reading one announcement (2026-11-22): who wrote it and when it last
 * changed, and a notification that opens the story rather than the list.
 */
function readingUrl(Tenant $tenant, string $path = '/announcements'): string
{
    return "http://{$tenant->subdomain}.ethr.test/api/v1{$path}";
}

it('names the author and when the story last changed', function () {
    $tenant = createTenant();
    $admin = actingAsUser(['role' => UserRole::HR_ADMIN, 'display_name' => 'Hanna Bekele'], $tenant);

    $story = Announcement::factory()->create([
        'tenant_id' => $tenant->id,
        'published_by' => $admin->id,
        'published_at' => now()->subHour(),
    ]);

    $row = test()->getJson(readingUrl($tenant, "/announcements/{$story->public_id}"))
        ->assertOk()
        ->json();

    expect($row['author']['name'])->toBe('Hanna Bekele')
        ->and($row['updated_at'])->not->toBeNull();
});

it("takes the author's name from their employee record when they have one", function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Dawit Alemu']);
    $admin = actingAsUser([
        'role' => UserRole::HR_ADMIN,
        'display_name' => 'ignored while an employee record exists',
        'employee_id' => $employee->id,
    ], $tenant);

    $story = Announcement::factory()->create([
        'tenant_id' => $tenant->id,
        'published_by' => $admin->id,
        'published_at' => now()->subHour(),
    ]);

    test()->getJson(readingUrl($tenant, "/announcements/{$story->public_id}"))
        ->assertOk()
        ->assertJsonPath('author.name', 'Dawit Alemu');
});

it('reads the list without a query for each row, however many rows there are', function () {
    $tenant = createTenant();
    $admin = actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    $make = fn (int $n) => Announcement::factory()->count($n)->create([
        'tenant_id' => $tenant->id,
        'published_by' => $admin->id,
        'published_at' => now()->subHour(),
    ]);

    // Warm first: the tenant and the caller's permissions are cached, and
    // counting those lookups would measure the cache, not the list.
    $make(3);
    test()->getJson(readingUrl($tenant))->assertOk();

    DB::enableQueryLog();
    test()->getJson(readingUrl($tenant))->assertOk();
    $three = count(DB::getQueryLog());

    $make(12);
    DB::flushQueryLog();
    test()->getJson(readingUrl($tenant))->assertOk();
    $fifteen = count(DB::getQueryLog());
    DB::disableQueryLog();

    // The author is eager-loaded, so five times the rows cost no more
    // queries. Read row by row, `User::name` loads each publisher's employee
    // record on its own, which `preventLazyLoading` turns into a 500 outside
    // production and a query per row inside it.
    expect($fifteen)->toBe($three);
});

it('sends a notification that opens the story, not the list', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::EMPLOYEE], $tenant);
    $story = Announcement::factory()->create([
        'tenant_id' => $tenant->id,
        'published_at' => now()->subHour(),
    ]);

    $payload = (new AnnouncementNotification($story))->toArray($user);

    expect($payload['url'])->toBe('/announcements?read='.$story->public_id)
        ->and($payload['announcement_id'])->toBe($story->public_id);
});

it('shows no author rather than the login email when the account has no name', function () {
    $tenant = createTenant();
    // What every administrator who registered before `display_name` existed
    // (2026-10-30, no backfill) still looks like: no employee record, no
    // display name. `User::name` falls back to the login email for them.
    $admin = actingAsUser([
        'role' => UserRole::HR_ADMIN,
        'display_name' => null,
        'employee_id' => null,
        'email' => 'admin@no-name.example',
    ], $tenant);

    $story = Announcement::factory()->create([
        'tenant_id' => $tenant->id,
        'published_by' => $admin->id,
        'published_at' => now()->subHour(),
    ]);

    $row = test()->getJson(readingUrl($tenant, "/announcements/{$story->public_id}"))
        ->assertOk()
        ->json();

    expect($row['author'])->toBeNull()
        ->and(json_encode($row))->not->toContain('admin@no-name.example');
});
