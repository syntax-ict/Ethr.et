<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Jobs\NotifyAnnouncementAudienceJob;
use App\Models\Announcement;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\AnnouncementNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

/**
 * Publishing an announcement (2026-10-16): drafts, scheduling, unpublishing
 * and archiving through the two dates the model already had, a category and
 * a featured flag for the public news page, and an audience told exactly
 * once whatever the sequence of publish, unpublish and reschedule.
 */
function publishingUrl(Tenant $tenant, string $path = '/announcements'): string
{
    return "http://{$tenant->subdomain}.ethr.test/api/v1{$path}";
}

function publishingAdmin(Tenant $tenant): void
{
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
}

it('lists every state to a manager, and keeps drafts out of the reading list', function () {
    $tenant = createTenant();
    publishingAdmin($tenant);

    Announcement::factory()->create(['tenant_id' => $tenant->id, 'title' => 'Live', 'published_at' => now()->subHour()]);
    Announcement::factory()->create(['tenant_id' => $tenant->id, 'title' => 'Draft', 'published_at' => null]);
    Announcement::factory()->create(['tenant_id' => $tenant->id, 'title' => 'Later', 'published_at' => now()->addDay()]);
    Announcement::factory()->create(['tenant_id' => $tenant->id, 'title' => 'Old', 'published_at' => now()->subWeek(), 'expires_at' => now()->subDay()]);

    $manage = test()->getJson(publishingUrl($tenant, '/announcements?manage=1'))->assertOk();
    expect(collect($manage->json('data'))->mapWithKeys(fn ($a) => [$a['title'] => $a['status']])->all())
        ->toEqual(['Draft' => 'draft', 'Later' => 'scheduled', 'Live' => 'published', 'Old' => 'archived']);

    $reading = test()->getJson(publishingUrl($tenant))->assertOk();
    expect(collect($reading->json('data'))->pluck('title')->all())->toBe(['Live']);
});

it('refuses the publishing list to someone who cannot manage announcements', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::EMPLOYEE], $tenant);

    test()->getJson(publishingUrl($tenant, '/announcements?manage=1'))->assertForbidden();
});

it('schedules an announcement and tells its audience when it publishes, not before', function () {
    Queue::fake();
    $tenant = createTenant();
    publishingAdmin($tenant);
    $at = now()->addDay()->startOfMinute();

    $response = test()->postJson(publishingUrl($tenant), [
        'title' => 'Plant closes Friday',
        'body' => 'Maintenance shutdown.',
        'published_at' => $at->toIso8601String(),
        'category' => 'notice',
    ])->assertCreated()->assertJsonPath('status', 'scheduled')->assertJsonPath('category', 'notice');

    Queue::assertPushed(NotifyAnnouncementAudienceJob::class, fn ($job) => $job->delay !== null);
    expect(Announcement::query()->where('public_id', $response->json('public_id'))->value('notified_at'))->toBeNull();
});

it('queues nothing for a draft, and notifies once it is published', function () {
    Queue::fake();
    $tenant = createTenant();
    publishingAdmin($tenant);

    $id = test()->postJson(publishingUrl($tenant), [
        'title' => 'Draft memo',
        'body' => 'Not yet.',
        'publish_now' => false,
    ])->assertCreated()->assertJsonPath('status', 'draft')->json('public_id');

    Queue::assertNothingPushed();

    test()->putJson(publishingUrl($tenant, "/announcements/{$id}"), ['published_at' => now()->toIso8601String()])
        ->assertOk()
        ->assertJsonPath('status', 'published');

    Queue::assertPushed(NotifyAnnouncementAudienceJob::class, 1);
    test()->assertDatabaseHas('audit_log', ['action' => 'announcement.published']);
});

it('tells the audience once, however often the announcement is published again', function () {
    Notification::fake();
    $tenant = createTenant();
    createUser(['role' => UserRole::EMPLOYEE], $tenant);
    $announcement = Announcement::factory()->create([
        'tenant_id' => $tenant->id,
        'target_type' => 'all',
        'published_at' => now()->subMinute(),
        'notified_at' => null,
    ]);

    $job = new NotifyAnnouncementAudienceJob($announcement->id, $tenant->id);
    app()->call([$job, 'handle']);
    app()->call([$job, 'handle']);

    $sent = Notification::sent(User::query()->where('tenant_id', $tenant->id)->where('role', UserRole::EMPLOYEE)->first(), AnnouncementNotification::class);
    expect($sent)->toHaveCount(1)
        ->and($announcement->fresh()->notified_at)->not->toBeNull();
});

it('unpublishes to a draft and archives by expiry, and records each as itself', function () {
    Queue::fake();
    $tenant = createTenant();
    publishingAdmin($tenant);
    $announcement = Announcement::factory()->create(['tenant_id' => $tenant->id, 'published_at' => now()->subHour(), 'notified_at' => now()->subHour()]);
    $url = publishingUrl($tenant, "/announcements/{$announcement->public_id}");

    test()->putJson($url, ['published_at' => null])->assertOk()->assertJsonPath('status', 'draft');
    test()->assertDatabaseHas('audit_log', ['action' => 'announcement.unpublished']);

    test()->putJson($url, ['published_at' => now()->subMinute()->toIso8601String()])->assertOk()->assertJsonPath('status', 'published');
    // Told already: publishing it again queues nothing.
    Queue::assertNothingPushed();

    test()->putJson($url, ['expires_at' => now()->subSecond()->toIso8601String()])->assertOk()->assertJsonPath('status', 'archived');
    test()->assertDatabaseHas('audit_log', ['action' => 'announcement.archived']);
});

it('keeps featured and category on the announcement, and refuses an unknown category', function () {
    $tenant = createTenant();
    publishingAdmin($tenant);

    test()->postJson(publishingUrl($tenant), [
        'title' => 'Open day',
        'body' => 'Visit us.',
        'category' => 'event',
        'featured' => true,
        'show_on_landing' => true,
    ])->assertCreated()->assertJsonPath('featured', true)->assertJsonPath('category', 'event');

    test()->postJson(publishingUrl($tenant), [
        'title' => 'Bad',
        'body' => 'x',
        'category' => 'gossip',
    ])->assertUnprocessable()->assertJsonValidationErrors('category');
});

it('lets one organisation manage only its own announcements', function () {
    $other = createTenant();
    $theirs = Announcement::factory()->create(['tenant_id' => $other->id, 'published_at' => null]);

    $tenant = createTenant();
    publishingAdmin($tenant);

    test()->putJson(publishingUrl($tenant, "/announcements/{$theirs->public_id}"), ['published_at' => now()->toIso8601String()])
        ->assertNotFound();
    test()->getJson(publishingUrl($tenant, '/announcements?manage=1'))
        ->assertOk()
        ->assertJsonCount(0, 'data');
    expect($theirs->fresh()->published_at)->toBeNull();
});
