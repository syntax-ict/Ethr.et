<?php

declare(strict_types=1);

/**
 * Three facts about this deployment live in three different files, and nothing
 * kept them agreeing.
 *
 *   api/composer.json                              what composer enforces
 *   scripts/hosting-verification/…-check.php       what the probe reports
 *   api/.env.shared-hosting.example                what the operator copies
 *
 * Each drifted at least once before this test existed:
 *
 * - `composer.json` declared **no** `ext-*` at all while the probe listed 18 as
 *   mandatory. Three of them — `pdo`, `pdo_mysql`, `gd` — are declared by no
 *   production package either, so `composer install` could not detect them
 *   missing and `gd`'s absence is silent: `FileStorageService::stripExif()`
 *   returns the original bytes, so uploaded employee photographs and identity
 *   documents keep their EXIF.
 *
 * - The probe's mandatory list carried `bcmath`, which appears in
 *   `composer.lock` only under `suggest` and which the application never calls,
 *   and carried `zip` as mandatory when the requirement is `phar` OR `zip`.
 *
 * - `.env.production.example` is a docker-compose artifact selecting redis,
 *   minio and reverb, and `DEPLOYMENT.md` step 4 says to copy it. On Plesk that
 *   yields an application that cannot boot, which is why the shared-hosting
 *   template exists — and why it must not silently lose a key the VPS template
 *   has, or silently regain a driver that target cannot run.
 *
 * These assertions are cheap and need no database, no network and no vendor
 * beyond Pest itself.
 */
function ethrRepoPath(string $relative): string
{
    return dirname(base_path()).'/'.$relative;
}

function ethrEnvKeys(string $path): array
{
    preg_match_all('/^([A-Z][A-Z0-9_]*)=/m', (string) file_get_contents($path), $m);
    $keys = array_unique($m[1]);
    sort($keys);

    return $keys;
}

function ethrProbeMandatory(): array
{
    $probe = (string) file_get_contents(
        ethrRepoPath('scripts/hosting-verification/ethr-hosting-check.php')
    );

    // Match only the array literal, so the explanatory comments that follow it
    // cannot contribute stray quoted words to the result.
    expect(preg_match('/\$mandatory = \[(.*?)\n\];/s', $probe, $block))->toBe(1);
    preg_match_all("/'([a-z_]+)'/", $block[1], $m);

    return $m[1];
}

it('declares every composer ext-* requirement in the probe mandatory list', function () {
    $composer = json_decode((string) file_get_contents(base_path('composer.json')), true);

    $declared = array_map(
        static fn (string $k): string => substr($k, 4),
        array_values(array_filter(
            array_keys($composer['require']),
            static fn (string $k): bool => str_starts_with($k, 'ext-')
        ))
    );

    // composer.json must not require a platform package the probe never checks:
    // the operator would enable extensions from the probe output and still hit
    // an aborted install.
    expect($declared)->not->toBeEmpty(
        'composer.json declares no ext-* at all. It did once, and pdo, pdo_mysql '
        .'and gd are declared by no production package either, so nothing caught them.'
    );

    foreach ($declared as $ext) {
        expect(ethrProbeMandatory())->toContain(
            $ext,
            "composer.json requires ext-$ext but the probe does not list it as mandatory."
        );
    }
});

it('keeps the probe mandatory list free of extensions nothing requires', function () {
    $mandatory = ethrProbeMandatory();

    // bcmath is the measured case: four occurrences in composer.lock, every one
    // under `suggest`, and zero bc* calls in the application because money is
    // stored in integer minor units. Asking the host for it wastes a support
    // round trip.
    expect($mandatory)->not->toContain('bcmath');

    // zip is the other: BackupService tries PharData, falls back to ZipArchive,
    // and throws a RuntimeException naming the remedy if neither exists. The
    // pair is required; neither member is, and listing only zip hid phar.
    expect($mandatory)->not->toContain('zip');

    expect($mandatory)->toHaveCount(18);
});

it('keeps the shared-hosting env template key-identical to the VPS template', function () {
    $vps = ethrEnvKeys(base_path('.env.production.example'));
    $shared = ethrEnvKeys(base_path('.env.shared-hosting.example'));

    // Losing a key here is how a production deployment ends up missing a
    // variable that config/ reads with no default — the CONTACT_INBOX class of
    // defect, found once already.
    expect($shared)->toBe(
        $vps,
        'The shared-hosting template must carry exactly the VPS template keys. '
        .'Conversions change VALUES and are marked # [shared-hosting]; they never drop a key.'
    );
});

it('selects no driver the shared-hosting target cannot run', function () {
    $env = (string) file_get_contents(base_path('.env.shared-hosting.example'));

    $forbidden = [
        'CACHE_STORE' => 'redis',
        'QUEUE_CONNECTION' => 'redis',
        'SESSION_DRIVER' => 'redis',
        'BROADCAST_CONNECTION' => 'reverb',
        'FILESYSTEM_DISK' => 'minio',
    ];

    foreach ($forbidden as $key => $driver) {
        expect($env)->not->toMatch(
            "/^{$key}={$driver}\\s*$/m",
            "$key={$driver} needs a service this account does not have."
        );
    }

    // BROADCAST_CONNECTION must stay the literal string `null` rather than being
    // left empty: BroadcastConnectionConfigTest records that env() parses "null"
    // into PHP null, and config/broadcasting.php coalesces it back. An empty
    // value falls through to the `reverb` default instead, which throws on the
    // first broadcast — so a successful write returns 500.
    expect($env)->toMatch('/^BROADCAST_CONNECTION=null\s/m');

    // Docker service hostnames resolve to nothing outside a compose network.
    expect($env)->not->toMatch('/^REDIS_HOST=redis\s*$/m');
    expect($env)->not->toMatch('#^MINIO_ENDPOINT=http://minio:9000\s*$#m');
});

it('matches the driver swaps ENVIRONMENT.md D-8 already decided', function () {
    // Forbidding `redis` is not enough, and this test learned that the hard way:
    // the template first shipped CACHE_STORE=file and SESSION_DRIVER=file, which
    // pass every assertion above and still contradict a decision that was
    // LIVE-VERIFIED against real MariaDB on 2026-08-31.
    //
    // CACHE_STORE=database is load-bearing. `config/database.php`'s `cache_locks`
    // table is what Laravel's database cache driver needs for `Cache::lock()`,
    // the primitive `->withoutOverlapping()` uses internally at 8 call sites in
    // routes/console.php. `file` moves those locks onto an implementation nobody
    // verified, for no gain.
    $env = (string) file_get_contents(base_path('.env.shared-hosting.example'));

    $decided = [
        'CACHE_STORE' => 'database',
        'QUEUE_CONNECTION' => 'database',
        'SESSION_DRIVER' => 'database',
        'FILESYSTEM_DISK' => 'local',
    ];

    foreach ($decided as $key => $value) {
        expect($env)->toMatch(
            "/^{$key}={$value}\\s/m",
            "$key must be `$value` — ENVIRONMENT.md D-8 decided it and MIGRATION_STATE records why."
        );
    }

    // BROADCAST_CONNECTION is the one deliberate divergence: D-8 says `log`,
    // this template says `null`. Both report `disabled` through
    // SystemHealthService::broadcastStatus(), config/broadcasting.php coalesces
    // to `null` as its own fallback, CI sets `null`, and `log` would write a
    // line per broadcast against a fixed disk quota. Recorded in
    // MIGRATION_STATE rather than left as an unexplained difference.
    expect($env)->toMatch('/^BROADCAST_CONNECTION=null\s/m');
});
