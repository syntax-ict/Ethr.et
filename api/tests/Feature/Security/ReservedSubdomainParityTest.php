<?php

declare(strict_types=1);

use App\Models\Tenant;

/**
 * The backend and frontend reserved-subdomain lists must agree.
 *
 * There are two copies of "which hostnames are not tenants":
 * `Tenant::RESERVED_SUBDOMAINS` and `RESERVED_LABELS` in
 * `src/src/lib/auth/host-context.ts`. Both files say they are kept in step by
 * hand, and they have already drifted once — the browser read `www.ethr.et` as
 * a tenant named "www" and rendered "Sign in to www" with the organisation
 * field hidden, while the API answered `{tenant: null}`. A login form on an
 * apex alias with no way to say which organisation you belong to.
 *
 * Public landing pages raise the cost of the next drift: the frontend deciding
 * a reserved host is a tenant now means it also expects a public page there.
 * So the invariant gets a test rather than a comment.
 *
 * Parsing TypeScript from PHP is admittedly crude. The alternative — a
 * generated file — is better and is a larger change than this feature should
 * carry; this at least makes the drift loud.
 */
it('keeps the frontend reserved-label list identical to the backend one', function () {
    $hostContext = base_path('../src/src/lib/auth/host-context.ts');

    // The frontend is not present in every checkout (a backend-only container,
    // for instance). Skipping is honest; asserting against a file that is not
    // there would fail for the wrong reason.
    if (! file_exists($hostContext)) {
        test()->markTestSkipped('Frontend source not present in this checkout.');
    }

    $source = (string) file_get_contents($hostContext);

    expect(preg_match('/const RESERVED_LABELS = new Set\(\[(.*?)\]\)/s', $source, $matches))
        ->toBe(1, 'Could not find RESERVED_LABELS in host-context.ts — has it been renamed?');

    preg_match_all('/"([a-z0-9-]+)"/', $matches[1], $labels);

    $frontend = $labels[1];
    $backend = Tenant::RESERVED_SUBDOMAINS;

    sort($frontend);
    sort($backend);

    expect($frontend)->toBe($backend, implode("\n", [
        'Tenant::RESERVED_SUBDOMAINS and host-context.ts RESERVED_LABELS have drifted.',
        'Only in backend: '.implode(', ', array_diff($backend, $frontend)),
        'Only in frontend: '.implode(', ', array_diff($frontend, $backend)),
        'Update both in the same commit — a name reserved in one and not the',
        'other produces a host the two halves of the application disagree about.',
    ]));
});

it('agrees with the frontend on which label is the platform host', function () {
    $hostContext = base_path('../src/src/lib/auth/host-context.ts');

    if (! file_exists($hostContext)) {
        test()->markTestSkipped('Frontend source not present in this checkout.');
    }

    $source = (string) file_get_contents($hostContext);

    expect(preg_match('/const PLATFORM_LABEL = "([a-z0-9-]+)"/', $source, $matches))->toBe(1);
    expect($matches[1])->toBe(Tenant::PLATFORM_SUBDOMAIN);
});

it('reserves every hostname the deployment actually uses', function () {
    // Not an exhaustive list — it is the set whose absence would be a concrete
    // operational problem, drawn from the nginx config, the deployment docs and
    // the platform host. `dev`, `staging` and `test` matter because those are
    // real environments; `api` and `app` because they are the names an operator
    // reaches for first.
    expect(Tenant::RESERVED_SUBDOMAINS)->toContain(
        'admin', 'platform', 'www', 'api', 'app', 'dev', 'staging', 'test',
        'mail', 'ftp', 'support',
    );
});

it('holds no duplicate or malformed reserved name', function () {
    $reserved = Tenant::RESERVED_SUBDOMAINS;

    expect($reserved)->toBe(array_values(array_unique($reserved)), 'Duplicate reserved subdomain.');

    foreach ($reserved as $name) {
        // A reserved name that could not be a valid subdomain anyway reserves
        // nothing, and hides the fact that the real name is still free.
        expect($name)->toMatch('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/');
    }
});
