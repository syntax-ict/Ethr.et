<?php

declare(strict_types=1);

/**
 * The Layer-4 check `audit/BASELINE.md` §15 row 11 records as missing.
 *
 * `BelongsToTenant` is fail-closed: with no tenant resolved its global scope
 * applies `whereRaw('0 = 1')`, so the absence of context yields *no* rows rather
 * than every tenant's. That is the single property this product's safety rests
 * on, and `withoutGlobalScope` / `withoutGlobalScopes` turns it off.
 *
 * Most of the 156 call sites are legitimate — platform-admin surfaces,
 * pre-authentication lookups, global reference data, and queued jobs, which run
 * with no HTTP tenant context and must re-scope by hand. The rule is that every
 * one re-applies a tenant predicate. Nothing enforced that, and one was
 * measurably wrong and shipped (P0-1).
 *
 * This does not audit the existing sites; a count cannot. It makes adding one a
 * deliberate act, which is what was missing when P0-1 went in.
 */
it('has no unreviewed tenant-scope bypass', function () {
    $appDir = base_path('app');
    $expected = require __DIR__.'/tenant-scope-bypasses.php';

    $actual = [];

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($appDir, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $count = preg_match_all(
            '/withoutGlobalScopes?\s*\(/',
            (string) file_get_contents($file->getPathname())
        );

        if ($count > 0) {
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($appDir) + 1));
            $actual[$relative] = $count;
        }
    }

    ksort($actual);
    ksort($expected);

    // A directory scan that silently returns nothing would make this test pass
    // while checking nothing — the exact failure mode README.md documents for
    // Pest over a bind mount. Assert the scan found something first.
    expect($actual)->not->toBeEmpty(
        'Scanned app/ and found no bypasses at all, which cannot be right. '
        .'The directory scan failed rather than the invariant holding.'
    );

    $added = array_diff_key($actual, $expected);
    $removed = array_diff_key($expected, $actual);
    $changed = [];

    foreach (array_intersect_key($actual, $expected) as $path => $count) {
        if ($count !== $expected[$path]) {
            $changed[$path] = "{$expected[$path]} -> {$count}";
        }
    }

    $problems = [];

    foreach ($added as $path => $count) {
        $problems[] = "NEW FILE with {$count} bypass(es): {$path}";
    }

    foreach ($changed as $path => $delta) {
        $problems[] = "COUNT CHANGED ({$delta}): {$path}";
    }

    foreach ($removed as $path => $count) {
        $problems[] = "GONE (had {$count}): {$path}";
    }

    expect($problems)->toBe([], implode("\n", array_merge(
        ['', 'tests/Feature/Security/tenant-scope-bypasses.php is out of date:', ''],
        array_map(fn ($p) => '  '.$p, $problems),
        [
            '',
            'If a count went UP: open the new call site and answer one question —',
            'does the query state tenant_id itself, or derive from a key that is',
            'already tenant-owned? If yes, update the inventory. If no, you have',
            'found the next P0-1 (see BASELINE §11b).',
            '',
            'If it went DOWN: a bypass was removed. Update the inventory so it',
            'does not drift into fiction.',
            '',
        ]
    )));
});
