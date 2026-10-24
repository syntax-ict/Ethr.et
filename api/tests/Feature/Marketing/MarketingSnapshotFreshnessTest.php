<?php

declare(strict_types=1);

use App\Http\Resources\MarketingItemResource;
use App\Http\Resources\MarketingSectionResource;
use App\Models\MarketingItem;
use App\Models\MarketingSection;
use App\Support\MarketingClaims;
use App\Support\MarketingContent;

/**
 * The committed landing-page snapshot must still have the shape the public
 * endpoint publishes, and must make no claim the site may not make.
 *
 * `src/src/lib/marketing/content-snapshot.json` is what `/en` and `/am`
 * render into their prerendered HTML. Unlike the pricing snapshot, its
 * VALUES are not compared to the seeder: the platform operator edits this
 * page, the snapshot is regenerated from production before a release, and
 * the two are then expected to differ. What a developer can get wrong is
 * the shape, which is pinned here, and the claims, which the rule pins.
 */
function marketingSnapshotPath(): ?string
{
    $frontend = base_path('../src/src/lib/marketing');

    return is_dir($frontend) ? $frontend.'/content-snapshot.json' : null;
}

function marketingSnapshot(): array
{
    $path = marketingSnapshotPath();
    if ($path === null) {
        test()->markTestSkipped('Frontend tree not reachable: running from an isolated copy of api/.');
    }
    expect(file_exists($path))->toBeTrue("content-snapshot.json is missing at {$path}: the landing page would prerender empty.");

    return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
}

it('carries exactly the fields the public resources publish', function () {
    $snapshot = marketingSnapshot();

    $section = new MarketingSection;
    $section->setRelation('items', collect());
    $sectionKeys = array_keys((new MarketingSectionResource($section))->toArray(request()));
    $itemKeys = array_keys((new MarketingItemResource(new MarketingItem))->toArray(request()));

    expect($snapshot['data']['sections'])->not->toBeEmpty();
    foreach ($snapshot['data']['sections'] as $row) {
        expect(array_keys($row))->toEqualCanonicalizing($sectionKeys, 'content-snapshot.json no longer matches MarketingSectionResource. Regenerate with scripts/fetch-marketing-snapshot.mjs.');
        foreach ($row['items'] as $item) {
            expect(array_keys($item))->toEqualCanonicalizing($itemKeys, 'content-snapshot.json no longer matches MarketingItemResource. Regenerate with scripts/fetch-marketing-snapshot.mjs.');
        }
    }
});

it('names only known kinds and icons, within the registry, with a hero', function () {
    $snapshot = marketingSnapshot();
    $kinds = array_column($snapshot['data']['sections'], 'kind');

    expect($kinds)->toContain('hero');
    foreach ($snapshot['data']['sections'] as $row) {
        expect(MarketingContent::KINDS)->toContain($row['kind']);
        $spec = MarketingContent::spec($row['kind']);
        expect(count($row['items']))->toBeLessThanOrEqual($spec['max_items']);
        foreach ($row['items'] as $item) {
            if ($item['icon'] !== null) {
                expect(MarketingContent::ICONS)->toContain($item['icon']);
            }
        }
    }
});

it('makes no claim the public site may not make, and keeps the house style', function () {
    $snapshot = marketingSnapshot();
    $strings = [];
    foreach ($snapshot['data']['sections'] as $row) {
        foreach (['heading', 'heading_am', 'subheading', 'subheading_am'] as $f) {
            if (is_string($row[$f] ?? null)) {
                $strings["{$row['kind']}.{$f}"] = $row[$f];
            }
        }
        foreach ($row['items'] as $item) {
            foreach (['title', 'title_am', 'body', 'body_am', 'detail', 'detail_am'] as $f) {
                if (is_string($item[$f] ?? null)) {
                    $strings["{$row['kind']}.{$item['slug']}.{$f}"] = $item[$f];
                }
            }
        }
    }
    expect($strings)->not->toBeEmpty();
    foreach ($strings as $where => $text) {
        expect(MarketingClaims::violation($text))->toBeNull("{$where} makes a forbidden claim: {$text}")
            ->and($text)->not->toContain('—', "{$where} has a long dash")
            ->and(str_ends_with(trim($text), '!'))->toBeFalse("{$where} ends in an exclamation mark");
    }
});
