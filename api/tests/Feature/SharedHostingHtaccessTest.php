<?php

declare(strict_types=1);

/**
 * The deployable .htaccess is generated, and this is what stops the generator
 * from generating something that does not protect the deployment.
 *
 * `docs/deployment/shared-hosting/.htaccess` is a template: its BRANCH B rules
 * ship disabled because one file serves two deployment branches. Until
 * 2026-09-27 the runbook told a human to uncomment them, and three separate
 * hand-edit failures are on record in `docs/audit/BASELINE.md` §21:
 *
 *   §21a  skipping the two non-Rewrite directives left 20+ top-level routes
 *         404ing, because mod_dir answered /admin with a 301 before the SPA
 *         group was reached.
 *   §21b  serving 404.html with a RewriteRule returned HTTP **200**, so every
 *         unknown URL looked to a crawler like a page that loaded.
 *   §21c  a script that matched on directive names uncommented the English
 *         sentence "ErrorDocument, not a rewrite. Measured 2026-09-26: ..."
 *         and Apache 500'd the vhost. `httpd -t` said "Syntax OK", because it
 *         does not read .htaccess at all.
 *
 * `scripts/shared-hosting/render-htaccess.php` replaces the hand edit. The
 * template marks directives with a `#@ ` sentinel and the renderer strips
 * exactly that, so prose cannot be promoted. This test asserts the result —
 * both that the rules the deployment needs are present, and that the three
 * defects above cannot come back.
 *
 * WHAT THIS DOES NOT CLAIM. It is a structural check on generated text. It does
 * not fetch anything, and it is not evidence about Ethio Telecom's Apache:
 * whether `[F,L]` is honoured there is **M1** and still NOT VERIFIED, and
 * `AllowOverride Options` is a requirement this rule set introduced and nobody
 * has measured. A green run here means the artifact is correct as written, which
 * is what §21e calls LOCAL VERIFIED and carefully does not call more.
 *
 * No database, no network, no vendor beyond Pest.
 */

/** The BRANCH B artifact, rendered through the real script. */
const ETHR_TEST_ADMIN_HOST = 'admin.test.invalid';

function ethrRenderedHtaccess(): string
{
    $root = dirname(base_path());
    $script = $root.'/scripts/shared-hosting/render-htaccess.php';

    expect(is_file($script))->toBeTrue("renderer missing at {$script}");

    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    // --admin-host is REQUIRED since 2026-09-28: the platform console's hostname was
    // the literal admin.ethr.et, which denied /admin on the real admin host for any
    // other domain. A fixed test value here, asserted below.
    $process = proc_open(
        [PHP_BINARY, $script, '--branch=b', '--admin-host='.ETHR_TEST_ADMIN_HOST],
        $descriptors,
        $pipes,
        $root
    );

    expect(is_resource($process))->toBeTrue('could not start the renderer');

    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);

    expect($status)->toBe(0, "renderer exited {$status}: {$stderr}");
    expect($stdout)->not->toBe('', 'renderer produced no output');

    return $stdout;
}

/**
 * Just the lines Apache acts on — comments and blanks removed, indentation kept
 * off so ordering assertions read cleanly.
 *
 * @return list<string>
 */
function ethrHtaccessDirectives(string $rendered): array
{
    $out = [];

    foreach (explode("\n", $rendered) as $line) {
        $trimmed = trim($line);

        if ($trimmed === '' || str_starts_with($trimmed, '#')) {
            continue;
        }

        $out[] = $trimmed;
    }

    return $out;
}

/** Index of the first directive containing $needle, or null. */
function ethrHtaccessIndexOf(array $directives, string $needle): ?int
{
    foreach ($directives as $i => $line) {
        if (str_contains($line, $needle)) {
            return $i;
        }
    }

    return null;
}

// ── The rules the deployment cannot work without ────────────────────────────

it('renders the rewrite engine and the front controller', function () {
    $directives = ethrHtaccessDirectives(ethrRenderedHtaccess());

    expect($directives)->toContain('RewriteEngine On');

    // /api and /sanctum reach Laravel with no proxy directive. Measured
    // reaching index.php 18/18 on Apache 2.4.58 — BASELINE.md §21g.
    expect($directives)->toContain('RewriteCond %{REQUEST_URI} ^/(api|sanctum)(/|$) [NC]');
    expect($directives)->toContain('RewriteRule ^ index.php [L]');

    // Certificate renewal depends on this passthrough being unrewritten.
    expect($directives)->toContain('RewriteCond %{REQUEST_URI} ^/\.well-known/acme-challenge/ [NC]');

    // Sanctum needs both headers to survive FastCGI.
    expect($directives)->toContain('RewriteCond %{HTTP:Authorization} .');
    expect($directives)->toContain('RewriteCond %{HTTP:x-xsrf-token} .');
});

it('denies every path that must never be served', function () {
    $rendered = ethrRenderedHtaccess();
    $directives = ethrHtaccessDirectives($rendered);

    $deny = ethrHtaccessIndexOf($directives, '\.env.*');

    expect($deny)->not->toBeNull('the .env deny rule is absent from the rendered output');

    $rule = $directives[$deny];

    // Each of these is a separate exposure. .env carries APP_KEY and the
    // database password; composer.json and composer.lock enumerate the
    // dependency surface; artisan and phpunit.xml should never be reachable.
    // .user.ini sits in the document root on purpose — it carries the PHP
    // limits the panel will not let the account set — and is configuration,
    // not content.
    foreach (['\.env', '\.git', 'composer\.', 'artisan', 'phpunit\.xml', '\.user\.ini'] as $protected) {
        expect($rule)->toContain($protected);
    }

    // [F,L] is the mechanism, not <FilesMatch>. This matters because the host
    // canary proved <FilesMatch> works and has never tested [F,L] — that
    // asymmetry is M1, and a reader must not mistake one for the other.
    expect($rule)->toEndWith('- [F,L]');

    // storage/ and bootstrap/cache/ if either is ever misplaced under the root.
    expect($directives)->toContain('RewriteRule ^(storage|bootstrap/cache)(/|$) - [F,L]');
});

it('keeps the /admin host boundary ahead of every catch-all', function () {
    $directives = ethrHtaccessDirectives(ethrRenderedHtaccess());

    $adminDeny = ethrHtaccessIndexOf($directives, 'RewriteRule ^admin(/|$) - [F,L]');
    $spaFallback = ethrHtaccessIndexOf($directives, 'RewriteRule ^(.+?)/?$ /$1.html [L]');

    expect($adminDeny)->not->toBeNull('the /admin deny rule is absent');
    expect($spaFallback)->not->toBeNull('the SPA fallback is absent');

    // THIS IS THE ASSERTION THAT MATTERS. The deny used to sit after the
    // fallback. The fallback matches /admin, admin.html exists, and [L] ends
    // the round — so /admin on a tenant host measured 200 instead of 403.
    // A deny rule placed after a catch-all is not a deny rule.
    expect($adminDeny)->toBeLessThan(
        $spaFallback,
        'the /admin deny is ordered AFTER the SPA fallback, where it can never fire. '
        .'Measured 200-instead-of-403 on 2026-09-26; see BASELINE.md §20e.'
    );

    // The host predicate, with the placeholder substituted and REGEX-ESCAPED. An
    // unescaped dot matches any character, so admin.test.invalid would also match
    // adminXtest.invalid — a wider boundary than intended.
    $escaped = preg_quote(ETHR_TEST_ADMIN_HOST, '/');

    expect($directives)->toContain('RewriteCond %{HTTP_HOST} !^'.$escaped.'$ [NC]');
    expect($directives)->toContain('RewriteCond %{HTTP_HOST} ^'.$escaped.'$ [NC]');

    // And no placeholder may survive into a deployable artifact.
    expect($directives)->not->toContain('__ADMIN_HOST__');
});

it('serves an unknown URL as a real 404 rather than a rewritten 200', function () {
    $directives = ethrHtaccessDirectives(ethrRenderedHtaccess());

    // ErrorDocument sets the status. A rewrite is an internal substitution and
    // does not, which is why the first version of this rule served 404.html
    // with HTTP 200 — BASELINE.md §21b.
    expect($directives)->toContain('ErrorDocument 404 /404.html');

    foreach ($directives as $line) {
        expect($line)->not->toMatch(
            '/^RewriteRule\s.*404\.html/',
            'a RewriteRule pointing at 404.html returns HTTP 200, not 404. Use ErrorDocument.'
        );
    }
});

it('sends an organisation entry URL to Laravel, and nothing a page or asset owns', function () {
    $directives = ethrHtaccessDirectives(ethrRenderedHtaccess());

    $pattern = '[a-z0-9][a-z0-9-]{0,62}';
    $entryRule = 'RewriteRule ^('.$pattern.')/?$ index.php [L]';

    // Without it ethr.et/{slug} never reached OrganisationEntryController:
    // ErrorDocument answered first, so every organisation's entry URL, and
    // every e-mailed sign-in link built on it, was a 404.
    expect($directives)->toContain($entryRule);

    $ruleIndex = ethrHtaccessIndexOf($directives, $entryRule);

    // A RewriteCond binds to the next RewriteRule only, so the three guards must
    // be the three lines directly above it.
    expect(array_slice($directives, $ruleIndex - 3, 3))->toBe([
        'RewriteCond %{REQUEST_FILENAME} !-f',
        'RewriteCond %{REQUEST_FILENAME} !-d',
        'RewriteCond %{DOCUMENT_ROOT}/$1.html !-f',
    ]);

    // Exported pages win: /login and /dashboard are served by group 2 before
    // this rule can see them, and the /admin boundary precedes both.
    $spaFallback = ethrHtaccessIndexOf($directives, 'RewriteRule ^(.+?)/?$ /$1.html [L]');
    $adminDeny = ethrHtaccessIndexOf($directives, 'RewriteRule ^admin(/|$) - [F,L]');
    expect($spaFallback)->toBeLessThan($ruleIndex)
        ->and($adminDeny)->toBeLessThan($ruleIndex);

    // The pattern is the route's own constraint, so Apache never hands Laravel
    // a path its route refuses, nor refuses one the route would answer. It has
    // no dot, so no asset, exported .html or index.php itself can match.
    $routes = (string) file_get_contents(base_path('routes/web.php'));
    expect($routes)->toContain("->where('slug', '{$pattern}')");
    expect($pattern)->not->toContain('.');
});

it('falls back to the entity shell only when no real page exists at that path', function () {
    $directives = ethrHtaccessDirectives(ethrRenderedHtaccess());

    $shellRule = 'RewriteRule ^(employees|payroll|devices|admin/tenants)/([^/]+)$ /$1/__id__.html [L]';
    $guard = 'RewriteCond %{DOCUMENT_ROOT}/$1/$2.html !-f';

    expect($directives)->toContain($shellRule);

    // THE GUARD IS THE WHOLE POINT. Without it this rule matched /employees/new
    // — REQUEST_FILENAME is <DOCROOT>/employees/new, the exporter wrote
    // employees/new.html, so `!-f` passed and the "Add employee" page was served
    // the employee-DETAIL shell. Six static siblings live under these four
    // prefixes and all six were dead: /employees/new, /employees/import,
    // /payroll/cost-sharing, /payroll/loans, /payroll/payslips,
    // /devices/dashboard. Found 2026-09-27 by verify-static-export.mjs; §21d's
    // 20-of-20 matrix missed it because it never fetched a static sibling under
    // a dynamic prefix.
    expect($directives)->toContain($guard);

    $guardIndex = ethrHtaccessIndexOf($directives, $guard);
    $ruleIndex = ethrHtaccessIndexOf($directives, $shellRule);

    // A RewriteCond only applies to the RewriteRule that immediately follows it.
    expect($guardIndex)->toBe(
        $ruleIndex - 1,
        'the DOCUMENT_ROOT guard must sit immediately above the shell rule; a condition '
        .'separated from its rule applies to a different rule entirely.'
    );

    // The id segment has to be captured for the guard to be able to test it.
    expect($shellRule)->toContain('([^/]+)');
});

it('carries the two non-Rewrite directives a name-matching script skipped', function () {
    $directives = ethrHtaccessDirectives(ethrRenderedHtaccess());

    // Without DirectorySlash Off, mod_dir 301s /admin to /admin/ before the SPA
    // group is reached and the route is dead. Without Options -Indexes, a
    // directory request Apache cannot resolve becomes eligible for a listing.
    // Both measured — BASELINE.md §21a. They need AllowOverride Options, which
    // is a NEW host requirement and is NOT VERIFIED on Ethio Telecom.
    expect($directives)->toContain('DirectorySlash Off');
    expect($directives)->toContain('Options -Indexes');
});

it('sends the full security header set, with the CSP that lets the export hydrate', function () {
    $directives = ethrHtaccessDirectives(ethrRenderedHtaccess());
    $joined = implode("\n", $directives);

    foreach ([
        'X-Frame-Options',
        'X-Content-Type-Options',
        'Strict-Transport-Security',
        'Referrer-Policy',
        'Permissions-Policy',
        'Content-Security-Policy',
    ] as $header) {
        expect($joined)->toContain($header);
    }

    $csp = null;

    foreach ($directives as $line) {
        if (str_contains($line, 'Content-Security-Policy')) {
            $csp = $line;
        }
    }

    expect($csp)->not->toBeNull();

    // 'unsafe-inline' is load-bearing and measured: the export emits three
    // un-nonced inline scripts (~13 KB), and script-src 'self' alone gave 3 CSP
    // violations per page, React error #412 and nothing hydrating while the HTML
    // still rendered. BASELINE.md §20f.
    expect($csp)->toContain("script-src 'self' 'unsafe-inline'");

    // 'unsafe-eval' is measured UNNECESSARY here, so this policy stays tighter
    // than next.config.ts's. Do not widen it to match.
    expect($csp)->not->toContain('unsafe-eval');

    // No wss: — Reverb is not deployed on this target.
    expect($csp)->not->toContain('wss:');

    // The three directives nginx never carried.
    foreach (["frame-ancestors 'none'", "base-uri 'self'", "form-action 'self'"] as $directive) {
        expect($csp)->toContain($directive);
    }
});

// ── The renderer's own discipline ───────────────────────────────────────────

it('never marks the service worker immutable', function () {
    // The asset rule caches every .js for a year as "public, immutable", and
    // sw.js is a .js. It is the one script whose job is to change in place under
    // a fixed name (local production rehearsal, 2026-10-06). The override must
    // come AFTER the asset rule: Apache applies <Files> sections in order, so a
    // later one wins.
    $directives = ethrHtaccessDirectives(ethrRenderedHtaccess());

    $assets = ethrHtaccessIndexOf($directives, '<FilesMatch "\.(js|css');
    $worker = ethrHtaccessIndexOf($directives, '<Files "sw.js">');

    expect($assets)->not->toBeNull('the static asset caching rule is gone')
        ->and($worker)->not->toBeNull('sw.js has no cache override')
        ->and($worker)->toBeGreaterThan($assets);

    $block = array_slice($directives, $worker, 4);
    expect($block)->toContain('Header set Cache-Control "no-cache"')
        ->and($block)->toContain('</Files>');
});

it('promotes exactly the sentinel lines and no prose', function () {
    $root = dirname(base_path());
    $template = (string) file_get_contents($root.'/docs/deployment/shared-hosting/.htaccess');

    $sentinels = [];

    foreach (explode("\n", $template) as $line) {
        if (preg_match('/^\s*#@ (.*)$/', $line, $m) === 1) {
            $sentinels[] = trim($m[1]);
        }
    }

    // Pinned deliberately. Adding a fifth [id] route, or a rule of any kind,
    // must be a conscious act that updates this list — the same reason
    // TenantScopeBypassInventoryTest pins its inventory per file.
    // 20 → 24 on 2026-10-07: group 2b, the organisation entry URL (three
    // conditions and its rule). 24 → 37 on 2026-10-08: group 0b, per-page
    // segment prefetches (four depths × two conditions and a rule), and the
    // directory condition that stopped group 1's entity rule looping into a 500.
    expect($sentinels)->toHaveCount(
        37,
        'the sentinel set changed. If that is intended, update this count and say why in the '
        .'commit message; if it is not, a directive has been lost or a prose line marked.'
    );

    $directives = ethrHtaccessDirectives(ethrRenderedHtaccess());

    foreach ($sentinels as $sentinel) {
        // Apply the same substitution the renderer does, or the two host predicates
        // never match: the template carries __ADMIN_HOST__ and the output carries the
        // escaped hostname. Comparing them raw fails on a correct render.
        $expected = str_replace(
            '__ADMIN_HOST__',
            preg_quote(ETHR_TEST_ADMIN_HOST, '/'),
            $sentinel
        );

        expect($directives)->toContain($expected);
    }

    // And the inverse: no sentinel survives as a comment in the output.
    //
    // Matched per line, anchored. The rendered text DOES still contain the
    // characters `#@ ` inside prose — the header and the BRANCH B block both
    // document the convention by quoting it, which is the point of writing it
    // down. What must not survive is a LINE that still begins with the sentinel,
    // because that is a directive the renderer failed to enable.
    foreach (explode("\n", ethrRenderedHtaccess()) as $line) {
        expect($line)->not->toMatch(
            '/^\s*#@(?: |$)/',
            "a sentinel line survived unrendered: {$line}"
        );
    }
});

it('leaves prose alone even when it begins with a real directive name', function () {
    $directives = implode("\n", ethrHtaccessDirectives(ethrRenderedHtaccess()));

    // These three sentences live inside BRANCH B and each begins with a token
    // that is a genuine Apache directive — `Order` (mod_access_compat) and
    // `ErrorDocument`. A renderer matching on directive names turns them into
    // configuration and Apache 500s the whole vhost. BASELINE.md §21c.
    foreach ([
        'Ordering is therefore load-bearing',
        'Order matters.',
        'ErrorDocument, not a rewrite',
        'Disabling indexes is REQUIRED',
    ] as $prose) {
        // str_contains, not ->not->toContain($needle, $message): Pest's string
        // toContain takes VARARGS of needles, so a second argument intended as a
        // failure message quietly becomes a second assertion instead.
        expect(str_contains($directives, $prose))->toBeFalse(
            "prose was promoted to a directive: {$prose}"
        );
    }

    // Nothing in the rendered directive set may read as a sentence. Every line
    // Apache parses has to start with a directive name or a container tag.
    foreach (ethrHtaccessDirectives(ethrRenderedHtaccess()) as $line) {
        expect($line)->toMatch(
            '/^(<\/?[A-Za-z]|[A-Za-z_][A-Za-z0-9_]*\s)/',
            "not a directive: {$line}"
        );
    }
});

it('refuses to render BRANCH A, which is not owner-selected', function () {
    $root = dirname(base_path());

    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open(
        [PHP_BINARY, $root.'/scripts/shared-hosting/render-htaccess.php', '--branch=a'],
        $descriptors,
        $pipes,
        $root
    );

    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);

    // Hard rule 2 of SHARED-HOSTING-CONTRACT.md excludes the Plesk Node.js
    // application, and DEPLOYMENT.md §5 carries an unresolved precedence
    // conflict about it. Rendering BRANCH A would be choosing for the owner.
    expect($status)->toBe(2);
    expect($stderr)->toContain('BRANCH A');
    expect($stderr)->toContain('OWNER-DECISION-C5');
});

it('requires an admin host rather than defaulting to a domain', function () {
    $root = dirname(base_path());

    // No default, deliberately. The literal `admin.ethr.et` sat in the rule set until
    // 2026-09-28; rendered for any other domain the deny predicate reads "refuse
    // /admin unless the host is admin.ethr.et", so it refuses on the REAL admin host
    // and the platform console is unreachable — silently, because the rest of the site
    // is fine. A default would reintroduce exactly that.
    $process = proc_open(
        [PHP_BINARY, $root.'/scripts/shared-hosting/render-htaccess.php', '--target=static-export'],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $root
    );

    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);

    expect($status)->toBe(2);
    expect($stderr)->toContain('--admin-host is required');
    expect($stderr)->toContain('denies /admin on the real admin host');
});
