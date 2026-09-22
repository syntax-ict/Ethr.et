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
 *
 * ## Why the figure moved 156 -> 161 -> 156
 *
 * On 2026-09-22 four files carried four different counts: this docblock said
 * 156, the inventory beside it summed to 161, `docs/CLAUDE.md` said "~147 sites
 * do, nothing enforces it" — wrong on the number AND on the enforcement, since
 * this test is the enforcement — and the root `CLAUDE.md` said 161. They were
 * reconciled upward, to 161. The second assertion below exists so the documented
 * figure can no longer drift from the enforced one.
 *
 * **That reconciliation picked the wrong number.** Counting was `preg_match_all`
 * over raw file text, which cannot tell a call from the same words in a comment,
 * and five matches were comments — every one in a file whose docblock explains
 * why its bypass is safe. Counting is now tokenised
 * (`countTenantScopeBypassCalls` below) and the figure is 156 across 54 files:
 * `Http/Middleware/EnsurePlatformContext.php` leaves the inventory entirely,
 * because its only match was ever a docblock.
 *
 * The tempting inference here is wrong, so it is written down. It does *not*
 * follow that the 156 -> 161 rise was comments rather than bypasses. Measured
 * against the pin commit `21746a9` with the same tokeniser, that tree held
 * **151 real calls and the same 5 comments** — so the originally-recorded 156
 * was itself inflated, the five sites added between 09-16 and 09-18 were all
 * real, and the overcount has been a constant +5 throughout. The first pinned
 * figure (156) and the true figure today (156) are different quantities that
 * coincide.
 *
 * The lesson is the one this gate exists to teach, turned on itself: a control
 * that cannot distinguish the thing it guards from prose about that thing will
 * fire on the wrong events, and the people it fires on are the ones writing the
 * explanations.
 */
/**
 * Count real `withoutGlobalScope(s)` **calls** in one file.
 *
 * Tokenised rather than matched with a regex, and that is the point. A regex
 * over raw file text cannot tell a call from the same words written in a
 * comment, so `withoutGlobalScopes()` appearing in a docblock that *explains* a
 * bypass was counted as a bypass. Five were, on 2026-09-22 — in the five files
 * whose comments document why their bypass is safe. Writing a clear comment
 * tripped this gate; adding a bypass in silence tripped the same gate with the
 * same message, and nothing distinguished them.
 *
 * `token_get_all()` is PHP's own lexer, so T_COMMENT and T_DOC_COMMENT are
 * separate token types and simply skipped, as are string literals. A call is a
 * T_STRING naming the method followed by `(`, ignoring whitespace — which also
 * matches `withoutGlobalScope ('tenant')` the way the old regex's `\s*` did.
 */
function countTenantScopeBypassCalls(string $path): int
{
    $tokens = token_get_all((string) file_get_contents($path));
    $count = 0;
    $total = count($tokens);

    for ($i = 0; $i < $total; $i++) {
        $token = $tokens[$i];

        if (! is_array($token) || $token[0] !== T_STRING) {
            continue;
        }

        if ($token[1] !== 'withoutGlobalScope' && $token[1] !== 'withoutGlobalScopes') {
            continue;
        }

        $next = $i + 1;

        while ($next < $total && is_array($tokens[$next]) && $tokens[$next][0] === T_WHITESPACE) {
            $next++;
        }

        if ($next < $total && $tokens[$next] === '(') {
            $count++;
        }
    }

    return $count;
}

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

        $count = countTenantScopeBypassCalls($file->getPathname());

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

it('states the same bypass count in docs/CLAUDE.md as the inventory enforces', function () {
    // Four files carried four different counts of this on 2026-09-22 (147, 156,
    // 161, 161). The inventory beside this test is the enforced figure; the
    // conventions document is what a contributor reads first, and it was the
    // furthest out — it said "~147 sites do, nothing enforces it", which
    // understates the number and denies the existence of this very test.
    //
    // A reader who believes bypasses are unenforced works differently from one
    // who knows a pin will fail on the next one. That is why this is worth a
    // test rather than a one-time correction.
    $inventory = require __DIR__.'/tenant-scope-bypasses.php';
    $sites = array_sum($inventory);
    $files = count($inventory);

    $conventions = (string) file_get_contents(
        dirname(base_path()).'/docs/CLAUDE.md'
    );

    expect(preg_match('/\*\*(\d+) sites across (\d+) files\*\*/', $conventions, $m))->toBe(
        1,
        'docs/CLAUDE.md no longer states the bypass inventory as "**N sites across M files**". '
        .'Restore that phrasing in Non-Negotiable Convention #1 rather than deleting this test — '
        .'the drift it guards against is a documented count falling behind the enforced one.'
    );

    expect([(int) $m[1], (int) $m[2]])->toBe(
        [$sites, $files],
        'docs/CLAUDE.md states '.$m[1].' bypass sites across '.$m[2].' files; the inventory this '
        .'test enforces has '.$sites.' across '.$files.'. Update Convention #1 in the same change '
        .'that updates tenant-scope-bypasses.php.'
    );
});
