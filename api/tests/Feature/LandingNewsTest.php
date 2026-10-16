<?php

declare(strict_types=1);

use App\Models\Announcement;
use App\Models\Tenant;

/**
 * The organisation's public news page (2026-10-16): read before sign-in, so
 * most of these are about what must not appear - anything not marked public,
 * anything unpublished, anything from another organisation - and about the
 * page's own controls (featured first, search, category, pages) doing what
 * they say with real data.
 */
function newsUrl(Tenant $tenant, string $query = ''): string
{
    return "http://{$tenant->subdomain}.ethr.test/api/v1/auth/tenant-landing/news{$query}";
}

function newsItem(Tenant $tenant, array $attributes = []): Announcement
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

it('lists only public, published news, featured first', function () {
    $tenant = createTenant();
    newsItem($tenant, ['title' => 'Older', 'published_at' => now()->subDays(3)]);
    newsItem($tenant, ['title' => 'Newer', 'published_at' => now()->subDay()]);
    newsItem($tenant, ['title' => 'Headline', 'featured' => true, 'published_at' => now()->subDays(5)]);
    newsItem($tenant, ['title' => 'Internal', 'show_on_landing' => false]);
    newsItem($tenant, ['title' => 'Draft', 'published_at' => null]);
    newsItem($tenant, ['title' => 'Scheduled', 'published_at' => now()->addDay()]);
    newsItem($tenant, ['title' => 'Archived', 'expires_at' => now()->subMinute()]);
    newsItem($tenant, ['title' => 'Dept only', 'target_type' => 'department', 'target_id' => 1]);

    $response = test()->getJson(newsUrl($tenant))->assertOk();

    expect(collect($response->json('data'))->pluck('title')->all())->toBe(['Headline', 'Newer', 'Older'])
        ->and($response->json('data.0.featured'))->toBeTrue()
        ->and($response->json('meta.total'))->toBe(3);
});

it('pages the list nine at a time', function () {
    $tenant = createTenant();
    foreach (range(1, 11) as $i) {
        newsItem($tenant, ['title' => "Story {$i}", 'published_at' => now()->subMinutes($i)]);
    }

    test()->getJson(newsUrl($tenant))->assertOk()
        ->assertJsonCount(9, 'data')
        ->assertJsonPath('meta.last_page', 2);
    test()->getJson(newsUrl($tenant, '?page=2'))->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.1.title', 'Story 11');
});

it('searches title and text, treating the search as literal text', function () {
    $tenant = createTenant();
    newsItem($tenant, ['title' => 'Holiday schedule', 'body' => 'Offices close.']);
    newsItem($tenant, ['title' => 'Canteen menu', 'body' => 'New holiday dishes.']);
    newsItem($tenant, ['title' => 'Safety drill', 'body' => 'Fire drill Tuesday.']);

    $titles = fn (string $q) => collect(test()->getJson(newsUrl($tenant, '?q='.urlencode($q)))->assertOk()->json('data'))->pluck('title')->sort()->values()->all();

    expect($titles('holiday'))->toBe(['Canteen menu', 'Holiday schedule'])
        ->and($titles('%'))->toBe([])
        ->and($titles('_'))->toBe([]);
});

it('filters by category and offers only the categories in use', function () {
    $tenant = createTenant();
    newsItem($tenant, ['title' => 'Open day', 'category' => 'event']);
    newsItem($tenant, ['title' => 'Fees due', 'category' => 'deadline']);
    newsItem($tenant, ['title' => 'Untagged']);
    newsItem($tenant, ['title' => 'Private notice', 'category' => 'notice', 'show_on_landing' => false]);

    $response = test()->getJson(newsUrl($tenant, '?category=event'))->assertOk();

    expect(collect($response->json('data'))->pluck('title')->all())->toBe(['Open day'])
        ->and($response->json('categories'))->toBe(['deadline', 'event']);

    test()->getJson(newsUrl($tenant, '?category=gossip'))->assertUnprocessable();
});

it('has no news page when the organisation switched its news section off', function () {
    $tenant = createTenant();
    $tenant->update(['landing' => ['background' => 'brand', 'sections' => ['announcements' => false]]]);
    newsItem($tenant);

    test()->getJson(newsUrl($tenant))->assertNotFound();
});

it('never shows another organisation, with the same code serving both', function () {
    $acme = createTenant(['name' => 'Acme']);
    newsItem($acme, ['title' => 'Acme story']);
    $habru = createTenant(['name' => 'Habru']);
    $habruStory = newsItem($habru, ['title' => 'Habru story']);

    expect(collect(test()->getJson(newsUrl($acme))->json('data'))->pluck('title')->all())->toBe(['Acme story'])
        ->and(collect(test()->getJson(newsUrl($habru))->json('data'))->pluck('title')->all())->toBe(['Habru story']);

    // An article is found only through its own organisation's host.
    test()->getJson("http://{$acme->subdomain}.ethr.test/api/v1/auth/tenant-landing/announcements/{$habruStory->public_id}")
        ->assertNotFound();
});

it('opens a public article with related stories, the same category first, and refuses a draft', function () {
    $tenant = createTenant();
    $article = newsItem($tenant, ['title' => 'Open day', 'category' => 'event', 'body' => "First paragraph.\n\nSecond <b>bold</b> paragraph."]);
    newsItem($tenant, ['title' => 'Another event', 'category' => 'event', 'published_at' => now()->subDays(4)]);
    newsItem($tenant, ['title' => 'A notice', 'category' => 'notice', 'published_at' => now()->subMinute()]);
    $draft = newsItem($tenant, ['title' => 'Draft', 'published_at' => null]);

    $response = test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/auth/tenant-landing/announcements/{$article->public_id}")
        ->assertOk()
        ->assertJsonPath('category', 'event');

    expect($response->json('body'))->toBe("First paragraph.\n\nSecond bold paragraph.")
        ->and(collect($response->json('related'))->pluck('title')->all())->toBe(['Another event', 'A notice']);

    test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/auth/tenant-landing/announcements/{$draft->public_id}")
        ->assertNotFound();
});
