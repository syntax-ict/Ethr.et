<?php

declare(strict_types=1);

use App\Enums\AnnouncementCategory;
use App\Enums\AnnouncementPriority;
use App\Enums\AnnouncementTarget;
use App\Enums\UserRole;
use App\Models\Announcement;
use App\Models\Tenant;
use App\Support\Story;
use App\Support\TenantLanding;

/**
 * One story contract (news upgrade plan, N1): the same story gives the same
 * title, excerpt and body to the public article and to the signed-in read,
 * the two orders share one rank, and the three closed lists are enums.
 */
function contractUrl(Tenant $tenant, string $path): string
{
    return "http://{$tenant->subdomain}.ethr.test/api/v1{$path}";
}

function contractStory(Tenant $tenant, array $attributes = []): Announcement
{
    return Announcement::factory()->create([
        'tenant_id' => $tenant->id,
        'priority' => 'normal',
        'show_on_landing' => true,
        'featured' => false,
        'published_at' => now()->subHour(),
        ...$attributes,
    ]);
}

it('gives the public article and the signed-in read the same title, excerpt and body', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    $story = contractStory($tenant, [
        'title' => 'Long '.str_repeat('title ', 40),
        'body' => "<b>First</b> paragraph.\n\nSecond <script>alert(1)</script>paragraph.\n\n".str_repeat('More words. ', 60),
    ]);

    $public = test()->getJson(contractUrl($tenant, "/auth/tenant-landing/announcements/{$story->public_id}"))->assertOk()->json();
    $inApp = test()->getJson(contractUrl($tenant, "/announcements/{$story->public_id}"))->assertOk()->json();

    // The public card cuts a long title; the app keeps the stored one.
    expect($inApp['title'])->toBe($story->title)
        ->and($public['title'])->toBe(Str::limit($story->title, Story::TITLE_MAX))
        ->and($inApp['excerpt'])->toBe($public['excerpt'])
        ->and($inApp['body'])->toBe($public['body'])
        ->and($inApp['published_at'])->toBe($public['published_at'])
        ->and($inApp['body'])->not->toContain('<b>')
        ->and(mb_strlen($public['title']))->toBeLessThanOrEqual(Story::TITLE_MAX + 3);
});

it('reads a story through one presenter', function () {
    $tenant = createTenant();
    $story = contractStory($tenant, ['title' => 'Short', 'body' => "<i>Hello</i>\t\t world"]);

    $summary = Story::summary($story, $tenant->public_id);
    $article = Story::article($story, $tenant->public_id);

    expect($summary['title'])->toBe('Short')
        ->and($summary['excerpt'])->toBe('Hello world')
        ->and(Story::body($story))->toBe('Hello world')
        ->and($article)->toMatchArray([...$summary, 'body' => 'Hello world'])
        ->and($article)->toHaveKey('attachments');
});

it('answers the public article cacheable for two minutes, varying by host and tenant', function () {
    $tenant = createTenant();
    $story = contractStory($tenant);

    test()->getJson(contractUrl($tenant, "/auth/tenant-landing/announcements/{$story->public_id}"))
        ->assertOk()
        ->assertHeader('Cache-Control', 'max-age=120, private')
        ->assertHeader('Vary', 'Host, X-Tenant, Origin');
});

it('ranks low priority after normal in the public order and in the signed-in order', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    // Newest first would put the low story first if low ranked with normal.
    contractStory($tenant, ['title' => 'Low', 'priority' => 'low', 'published_at' => now()->subMinute()]);
    contractStory($tenant, ['title' => 'Normal', 'priority' => 'normal', 'published_at' => now()->subHours(2)]);
    contractStory($tenant, ['title' => 'High', 'priority' => 'high', 'published_at' => now()->subHours(3)]);
    contractStory($tenant, ['title' => 'Urgent', 'priority' => 'urgent', 'published_at' => now()->subHours(4)]);

    $public = collect(test()->getJson(contractUrl($tenant, '/auth/tenant-landing/news'))->assertOk()->json('data'))->pluck('title')->all();
    $inApp = collect(test()->getJson(contractUrl($tenant, '/announcements'))->assertOk()->json('data'))->pluck('title')->all();

    expect($public)->toBe(['Urgent', 'High', 'Normal', 'Low'])
        ->and($inApp)->toBe($public);
});

it('lets a reader open exactly what the list shows them', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::EMPLOYEE], $tenant);
    $live = contractStory($tenant);
    $draft = contractStory($tenant, ['published_at' => null]);
    $archived = contractStory($tenant, ['expires_at' => now()->subMinute()]);

    expect($live->isReadableBy($user))->toBeTrue()
        ->and($draft->isReadableBy($user))->toBeFalse()
        ->and($archived->isReadableBy($user))->toBeFalse()
        ->and(Announcement::query()->readableBy($user)->pluck('id')->all())->toBe([$live->id]);
});

it('asks whether an organisation shows news in one place', function () {
    $tenant = createTenant();
    expect(TenantLanding::showsNews($tenant))->toBeTrue();

    $tenant->update(['landing' => ['background' => 'brand', 'sections' => ['announcements' => false]]]);

    expect(TenantLanding::showsNews($tenant->fresh()))->toBeFalse();
});

it('lists each closed list once, with the values the forms and the database use', function () {
    expect(array_column(AnnouncementPriority::cases(), 'value'))->toBe(['low', 'normal', 'high', 'urgent'])
        ->and(array_column(AnnouncementCategory::cases(), 'value'))->toBe(['news', 'notice', 'event', 'deadline'])
        ->and(array_column(AnnouncementTarget::cases(), 'value'))->toBe(['all', 'department', 'branch']);
});

it('refuses a priority, category or target outside the lists', function (string $field, string $value) {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    $story = contractStory($tenant);

    test()->postJson(contractUrl($tenant, '/announcements'), ['title' => 'T', 'body' => 'B', $field => $value])
        ->assertUnprocessable()->assertJsonValidationErrors($field);
    test()->putJson(contractUrl($tenant, "/announcements/{$story->public_id}"), [$field => $value])
        ->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    'priority' => ['priority', 'critical'],
    'category' => ['category', 'gossip'],
    'target' => ['target_type', 'team'],
]);

it('refuses a news category outside the list', function () {
    $tenant = createTenant();

    test()->getJson(contractUrl($tenant, '/auth/tenant-landing/news?category=gossip'))
        ->assertUnprocessable()->assertJsonValidationErrors('category');
    test()->getJson(contractUrl($tenant, '/auth/tenant-landing/news?category=notice'))->assertOk();
});

it('hands the editor the stored title and body, so saving a story changes nothing', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    $title = 'Stored '.str_repeat('t', 193);
    $body = "Budget<2000 birr\n\nSecond <b>para</b>";
    $story = contractStory($tenant, ['title' => $title, 'body' => $body]);

    $read = test()->getJson(contractUrl($tenant, "/announcements/{$story->public_id}"))->assertOk()->json();

    expect(mb_strlen($read['title']))->toBe(200)
        ->and($read['title'])->toBe($title)
        ->and($read['body'])->not->toBe($body)
        ->and($read['body'])->not->toContain('<')
        ->and($read['body_source'])->toBe($body);

    test()->putJson(contractUrl($tenant, "/announcements/{$story->public_id}"), [
        'title' => $read['title'],
        'body' => $read['body_source'],
    ])->assertOk();

    expect($story->fresh()->title)->toBe($title)
        ->and($story->fresh()->body)->toBe($body);
});

it('gives the stored body only to someone who manages announcements', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::EMPLOYEE], $tenant);
    $story = contractStory($tenant, ['body' => 'Plain <b>words</b>']);

    $read = test()->getJson(contractUrl($tenant, "/announcements/{$story->public_id}"))->assertOk()->json();

    expect($read['body_source'])->toBeNull()
        ->and($read['body'])->toBe('Plain words');
});

it('cleans a body once and keeps its paragraphs', function () {
    $tenant = createTenant();
    $story = contractStory($tenant, ['body' => "First <i>para</i>.\n\nSecond  para."]);
    $once = Story::body($story);
    $story->body = $once;

    expect($once)->toBe("First para.\n\nSecond para.")
        ->and(Story::body($story))->toBe($once);
});
