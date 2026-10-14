<?php

declare(strict_types=1);

use App\Models\Announcement;
use App\Models\Holiday;
use App\Models\Tenant;
use App\Models\Vacancy;
use Illuminate\Support\Carbon;

/**
 * The public feed of an organisation's sign-in page. It is read before
 * sign-in, so the tests are about what must NOT appear: anything not marked
 * for the public, anything from another organisation, anything past its date,
 * and any markup.
 */
function feedUrl(Tenant $tenant, string $path = '/auth/tenant-landing/feed'): string
{
    return "http://{$tenant->subdomain}.ethr.test/api/v1{$path}";
}

function feedVacancy(Tenant $tenant, array $attributes = []): Vacancy
{
    return Vacancy::query()->create([
        'tenant_id' => $tenant->id,
        'title' => 'Machine operator',
        'employment_type' => 'full_time',
        'description' => 'Run the line.',
        'visibility' => 'public',
        'status' => 'open',
        'published_at' => now()->subDay(),
        ...$attributes,
    ]);
}

function feedAnnouncement(Tenant $tenant, array $attributes = []): Announcement
{
    return Announcement::query()->create([
        'tenant_id' => $tenant->id,
        'published_by' => createUser([], $tenant)->id,
        'title' => 'Plant reopens Monday',
        'body' => 'All shifts resume.',
        'priority' => 'normal',
        'target_type' => 'all',
        'published_at' => now()->subHour(),
        'show_on_landing' => true,
        ...$attributes,
    ]);
}

it('lists only what was marked for the public', function () {
    $tenant = createTenant();

    feedAnnouncement($tenant);
    feedAnnouncement($tenant, ['title' => 'Internal memo', 'show_on_landing' => false]);
    feedAnnouncement($tenant, ['title' => 'Draft', 'published_at' => null]);
    feedAnnouncement($tenant, ['title' => 'Expired', 'expires_at' => now()->subMinute()]);
    // Aimed at one department: never public, whatever the flag says.
    feedAnnouncement($tenant, ['title' => 'Dept only', 'target_type' => 'department', 'target_id' => 1]);

    feedVacancy($tenant);
    feedVacancy($tenant, ['title' => 'Internal role', 'visibility' => 'internal']);
    feedVacancy($tenant, ['title' => 'Draft role', 'status' => 'draft', 'published_at' => null]);
    feedVacancy($tenant, ['title' => 'Closed role', 'status' => 'closed']);
    feedVacancy($tenant, ['title' => 'Past deadline', 'closes_on' => Carbon::yesterday()->toDateString()]);
    feedVacancy($tenant, ['title' => 'Closes today', 'closes_on' => Carbon::today()->toDateString()]);

    $response = test()->getJson(feedUrl($tenant))->assertOk();

    expect(collect($response->json('announcements'))->pluck('title')->all())->toBe(['Plant reopens Monday'])
        ->and(collect($response->json('vacancies'))->pluck('title')->sort()->values()->all())->toBe(['Closes today', 'Machine operator'])
        // Holidays are off until the organisation turns them on.
        ->and($response->json('holidays'))->toBe([]);
});

it('never shows another organisation', function () {
    $mine = createTenant();
    $other = createTenant();
    feedAnnouncement($other, ['title' => 'Theirs']);
    feedVacancy($other, ['title' => 'Their role']);

    test()->getJson(feedUrl($mine))
        ->assertOk()
        ->assertExactJson(['announcements' => [], 'vacancies' => [], 'holidays' => []]);
});

it('sends announcement text as plain text, shortened', function () {
    $tenant = createTenant();
    feedAnnouncement($tenant, ['body' => '<script>alert(1)</script><b>Bold</b> '.str_repeat('a', 400)]);

    $excerpt = test()->getJson(feedUrl($tenant))->json('announcements.0.excerpt');

    expect($excerpt)->not->toContain('<')
        ->and(mb_strlen($excerpt))->toBeLessThanOrEqual(283);
});

it('respects the sections the organisation switched off and on', function () {
    $tenant = createTenant();
    feedAnnouncement($tenant);
    feedVacancy($tenant);
    Holiday::query()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Meskel',
        'name_am' => 'መስቀል',
        'date' => Carbon::today()->addDays(10)->toDateString(),
        'is_active' => true,
    ]);
    Holiday::query()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Branch day',
        'date' => Carbon::today()->addDays(5)->toDateString(),
        'is_active' => true,
        'branch_id' => null,
        'ethiopian_calendar' => false,
    ])->forceFill(['is_active' => false])->save();

    $tenant->update(['landing' => ['background' => 'brand', 'sections' => [
        'announcements' => false, 'vacancies' => false, 'holidays' => true,
    ]]]);

    test()->getJson(feedUrl($tenant))
        ->assertOk()
        ->assertJsonPath('announcements', [])
        ->assertJsonPath('vacancies', [])
        ->assertJsonPath('holidays', [['name' => 'Meskel', 'name_am' => 'መስቀል', 'date' => Carbon::today()->addDays(10)->toDateString()]]);
});

it('opens one public vacancy in full and hides every other', function () {
    $tenant = createTenant();
    $public = feedVacancy($tenant, [
        'requirements' => 'Two years on a CNC line.',
        'apply_email' => 'jobs@acme.et',
        'apply_url' => 'https://acme.et/careers',
    ]);
    $internal = feedVacancy($tenant, ['visibility' => 'internal']);
    $other = feedVacancy(createTenant());

    test()->getJson(feedUrl($tenant, "/auth/tenant-landing/vacancies/{$public->public_id}"))
        ->assertOk()
        ->assertJsonPath('title', 'Machine operator')
        ->assertJsonPath('requirements', 'Two years on a CNC line.')
        ->assertJsonPath('apply_email', 'jobs@acme.et')
        ->assertJsonPath('apply_url', 'https://acme.et/careers');

    foreach ([$internal, $other] as $hidden) {
        test()->getJson(feedUrl($tenant, "/auth/tenant-landing/vacancies/{$hidden->public_id}"))->assertNotFound();
    }
});

it('drops a stored apply link that is not https', function () {
    $tenant = createTenant();
    feedVacancy($tenant, ['apply_url' => 'javascript:alert(1)', 'apply_email' => 'not an email']);

    test()->getJson(feedUrl($tenant))
        ->assertJsonPath('vacancies.0.apply_url', null)
        ->assertJsonPath('vacancies.0.apply_email', null);
});

it('is cached briefly and varies by host and tenant header', function () {
    $tenant = createTenant();

    test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/auth/tenant-landing/feed")
        ->assertOk()
        ->assertHeader('Cache-Control', 'max-age=120, public')
        ->assertHeader('Vary', 'Host, X-Tenant, Origin');
});

it('opens a landing announcement in full, and nothing else', function () {
    $tenant = createTenant();
    $onLanding = feedAnnouncement($tenant, [
        'title' => 'Site maintenance on Saturday',
        'body' => 'The plant gate closes at noon.
<script>x</script>Bring your badge.',
    ]);
    $internal = feedAnnouncement($tenant, ['show_on_landing' => false]);
    $other = feedAnnouncement(createTenant());

    $url = fn (string $id) => feedUrl($tenant, "/auth/tenant-landing/announcements/{$id}");

    test()->getJson($url($onLanding->public_id))
        ->assertOk()
        ->assertJsonPath('title', 'Site maintenance on Saturday')
        ->assertJsonMissingPath('id')
        ->assertJsonPath('body', fn (string $body) => ! str_contains($body, '<script>')
            && str_contains($body, 'Bring your badge.'));

    test()->getJson($url($internal->public_id))->assertNotFound();
    test()->getJson($url($other->public_id))->assertNotFound();
});

it('keeps announcement detail behind the same section switch as the feed', function () {
    $tenant = createTenant();
    $tenant->update(['landing' => ['background' => 'brand', 'sections' => ['announcements' => false]]]);
    $announcement = feedAnnouncement($tenant);

    test()->getJson(feedUrl($tenant, "/auth/tenant-landing/announcements/{$announcement->public_id}"))
        ->assertNotFound();
});
