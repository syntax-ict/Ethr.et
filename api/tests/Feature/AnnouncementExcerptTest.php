<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Announcement;
use App\Support\PlainExcerpt;

/**
 * One way to shorten a story (2026-11-22): the in-app list and the public
 * feed read the same helper, so the same story reads the same everywhere,
 * and markup in a body never reaches either.
 */
it('shortens to plain text, without markup or runs of spaces', function () {
    expect(PlainExcerpt::of("<b>Hello</b>\t\t  world", 100))->toBe('Hello world')
        ->and(PlainExcerpt::of(str_repeat('a', 400), 280))->toHaveLength(283)
        ->and(PlainExcerpt::of(str_repeat('a', 400), 280))->toEndWith('...');
});

it('gives the announcements list the excerpt the public feed gives', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    $story = Announcement::factory()->create([
        'tenant_id' => $tenant->id,
        'title' => 'Closing early',
        'body' => '<script>alert(1)</script>We close at noon. '.str_repeat('More. ', 100),
        'published_at' => now()->subHour(),
    ]);

    $row = collect(
        test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/announcements")->assertOk()->json('data'),
    )->firstWhere('public_id', $story->public_id);

    expect($row['excerpt'])->toBe(PlainExcerpt::of($story->body, 280))
        ->and($row['excerpt'])->not->toContain('<script>');
});
