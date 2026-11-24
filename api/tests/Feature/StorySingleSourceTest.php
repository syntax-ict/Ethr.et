<?php

declare(strict_types=1);

use App\Http\Resources\AnnouncementResource;
use App\Models\Announcement;
use App\Support\Story;

/**
 * A story has one definition per question (news upgrade plan, N1), and this
 * is what stops the next copy being pasted in.
 *
 * Tokenised, not text-matched, for the reason `NotificationSeamTest` gives: a
 * comment that explains a rule must not count as a second copy of it.
 */
function storySourceFiles(): array
{
    $files = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('app'))) as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $files[] = str_replace('\\', '/', $file->getPathname());
        }
    }

    sort($files);

    return $files;
}

/** Whether PHP source writes a priority `CASE` inside a string, comments aside. */
function storyWritesPriorityCase(string $source): bool
{
    foreach (token_get_all($source) as $token) {
        if (is_array($token)
            && in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)
            && preg_match('/CASE\s+priority\b/i', $token[1]) === 1) {
            return true;
        }
    }

    return false;
}

/**
 * Whether PHP source reads the news section out of a section map: an
 * `['announcements']` index, however the map was got, comments aside. The
 * string token between `[` and `]` is what is looked for, so a two-step read
 * and a nested call are seen as well as `publicSections($t)['announcements']`.
 */
function storyReadsNewsSection(string $source): bool
{
    $tokens = array_values(array_filter(
        token_get_all($source),
        fn ($t) => ! is_array($t) || ! in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true),
    ));

    foreach ($tokens as $i => $token) {
        if (is_array($token)
            && $token[0] === T_CONSTANT_ENCAPSED_STRING
            && trim($token[1], '\'"') === 'announcements'
            && ($tokens[$i - 1] ?? null) === '['
            && ($tokens[$i + 1] ?? null) === ']') {
            return true;
        }
    }

    return false;
}

it('writes the priority rank in the model and nowhere else', function () {
    $files = storySourceFiles();
    $model = str_replace('\\', '/', base_path('app/Models/Announcement.php'));

    // A walk that found nothing would pass every check below; say so first.
    expect(count($files))->toBeGreaterThan(800)
        ->and($files)->toContain($model);

    $writers = array_values(array_filter($files, fn (string $f): bool => storyWritesPriorityCase((string) file_get_contents($f))));

    expect($writers)->toBe([$model]);
});

it('asks whether an organisation shows news in TenantLanding and nowhere else', function () {
    $files = storySourceFiles();
    $home = str_replace('\\', '/', base_path('app/Support/TenantLanding.php'));

    expect(count($files))->toBeGreaterThan(800)
        ->and($files)->toContain($home);

    $askers = array_values(array_filter($files, fn (string $f): bool => storyReadsNewsSection((string) file_get_contents($f))));

    // The feed already holds the whole section map it computed for the
    // sign-in page's other sections, so it reads the one key from it. Nothing
    // else may: every other caller asks TenantLanding::showsNews().
    $pinned = [str_replace('\\', '/', base_path('app/Http/Controllers/Api/V1/Auth/LandingFeedController.php'))];

    expect(array_values(array_diff($askers, $pinned)))->toBe([$home])
        ->and(array_values(array_intersect($askers, $pinned)))->toBe($pinned);
});

it('recognises the copies it forbids, and not a comment about them', function () {
    expect(storyWritesPriorityCase('<?php $q->orderByRaw("CASE priority WHEN \'urgent\' THEN 1 END");'))->toBeTrue()
        ->and(storyWritesPriorityCase("<?php // CASE priority WHEN 'urgent'\n/* CASE priority */ \$q->orderBy('id');"))->toBeFalse()
        ->and(storyReadsNewsSection('<?php if (! TenantLanding::publicSections($t)["announcements"]) {}'))->toBeTrue()
        ->and(storyReadsNewsSection('<?php $m = TenantLanding::publicSections($t); if ($m[\'announcements\']) {}'))->toBeTrue()
        ->and(storyReadsNewsSection('<?php foo(bar($t)["announcements"]);'))->toBeTrue()
        ->and(storyReadsNewsSection("<?php // publicSections(\$t)['announcements']\n\$x = TenantLanding::showsNews(\$t);"))->toBeFalse();
});

it('gives the signed-in resource every key the public summary has', function () {
    $tenant = createTenant();
    $story = Announcement::factory()->create(['tenant_id' => $tenant->id, 'published_at' => now()->subHour()]);

    $public = array_keys(Story::summary($story, $tenant->public_id));
    $inApp = array_keys((new AnnouncementResource($story))->toArray(request()));

    expect(array_diff($public, $inApp))->toBe([])
        ->and($public)->not->toBe([]);
});
