<?php

declare(strict_types=1);

/**
 * The enforceable half of `DEPLOYMENT.md` step 4a.
 *
 * The shared-hosting target merges the API and the public marketing site into
 * one document root (`~/httpdocs/`), where the VPS gave them separate origins.
 * Under that merge a real file in the document root always wins over the
 * rewrite — `.htaccess`'s front-controller rule is guarded by `!-f`/`!-d` — so
 * every file in `api/public/` either belongs there or silently overrides
 * whatever the application would otherwise have produced at that path.
 *
 * That defect already shipped once as documentation: §0 of the runbook listed
 * `robots.txt` as copied into the document root, which would have served an
 * allow-all robots.txt over the frontend's generated one, losing the Disallow
 * list and the Sitemap: pointer with the site fully functional and nothing
 * logged. The runbook is corrected — but a runbook is prose, and `CLAUDE.md` is
 * explicit that a documented control nobody runs is worse than an admitted gap
 * because it stops people looking. Add a file to `api/public/` tomorrow and the
 * identical defect re-opens with nothing to catch it.
 *
 * So this pins the inventory, in the same shape as
 * `Security/TenantScopeBypassInventoryTest` and for the same reason. It does
 * NOT audit the three existing decisions; a manifest cannot. It makes adding a
 * fourth file a deliberate act, which is exactly what was missing.
 *
 * This asserts repository shape, not runtime behaviour. Whether the host honours
 * any of it is gate G0-B, which requires the Plesk account and is unrun.
 */
it('has no file in api/public/ without a shared-document-root decision', function () {
    $publicDir = base_path('public');
    $expected = require __DIR__.'/document-root-inventory.php';

    $actual = [];

    foreach (new DirectoryIterator($publicDir) as $entry) {
        if ($entry->isDot() || $entry->isDir()) {
            continue;
        }

        $actual[$entry->getFilename()] = true;
    }

    ksort($actual);
    ksort($expected);

    // A directory scan that silently returns nothing would make this test pass
    // while checking nothing — the failure mode README.md documents for Pest
    // over a bind mount. Assert the scan found something first.
    expect($actual)->not->toBeEmpty(
        'Scanned api/public/ and found no files at all, which cannot be right — '
        .'index.php is the application\'s front controller and must be there. '
        .'The directory scan failed rather than the invariant holding.'
    );

    $added = array_diff_key($actual, $expected);
    $removed = array_diff_key($expected, $actual);

    $problems = [];

    foreach (array_keys($added) as $file) {
        $problems[] = "NEW FILE with no decision recorded: api/public/{$file}";
    }

    foreach (array_keys($removed) as $file) {
        $problems[] = "GONE, but still in the inventory: api/public/{$file}";
    }

    expect($problems)->toBe([], implode("\n", array_merge(
        ['', 'tests/Feature/document-root-inventory.php is out of date:', ''],
        array_map(fn ($p) => '  '.$p, $problems),
        [
            '',
            'For a NEW FILE, answer one question before adding it to the',
            'inventory: when api/public/ and the frontend build share a single',
            'document root, should this file be served from it?',
            '',
            '  NO  is the default. Most things here belong to the API origin the',
            '      VPS gave them, and copying one into ~/httpdocs/ permanently',
            '      overrides whatever the frontend serves at that path. That is',
            '      how robots.txt went wrong, and it fails silently.',
            '',
            '  YES needs a reason, and probably a line in DEPLOYMENT.md step 4a',
            '      saying how the file gets there and what has to change in it.',
            '      index.php is the only YES today.',
            '',
            'For a GONE file: record why it was removed, so the inventory does',
            'not drift into fiction.',
            '',
        ]
    )));
});
