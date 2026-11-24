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

it('removes markup and nothing else', function (string $text, string $expected) {
    expect(PlainExcerpt::of($text, 1000))->toBe($expected)
        ->and(PlainExcerpt::of($expected, 1000))->toBe($expected);
})->with([
    'a less-than before a digit' => ["Budget<2000 birr\n\nSecond <b>para</b>", "Budget<2000 birr\n\nSecond para"],
    'a heart' => ['I <3 you, ok', 'I <3 you, ok'],
    'a less-than before a letter, never closed' => ['x<y and more', 'x<y and more'],
    'a spaced less-than' => ['a < b', 'a < b'],
    'a script tag' => ['<script>alert(1)</script>hi', 'alert(1)hi'],
    'paragraphs' => ['<p>one</p>', 'one'],
    'a comment' => ['a<!-- hidden -->b', 'ab'],
    'a tag made by removing another' => ['<<b>script>x', 'x'],
]);

it('keeps a meta description on one line without losing a less-than sign', function () {
    expect(PlainExcerpt::line("Budget<2000 birr\n\n<b>now</b>", 100))->toBe('Budget<2000 birr now');
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
