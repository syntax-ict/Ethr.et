<?php

declare(strict_types=1);

use App\Services\FileStorageService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * These tests exist because four sites in three files hardcoded the *name* of a
 * piece of infrastructure — `'minio'`, `'redis'`, `'mariadb'` — where they meant
 * "whatever this deployment is configured to use".
 *
 * That distinction is invisible in an environment whose config happens to match
 * the literal, which is why it survived: production sets FILESYSTEM_DISK=minio
 * and CACHE_STORE=redis, so the two agreed and the bug had no symptom. It only
 * appears on a deployment that configures something else — and then it appears
 * as file storage silently ignoring FILESYSTEM_DISK, and as the health endpoint
 * reporting a permanently dead cache while the real cache is fine.
 *
 * Asserting "resolves the configured X" rather than "resolves minio/redis" is
 * what makes the regression catchable, so these tests deliberately point the
 * config at a *different* value and check the code follows it.
 */
test('file storage resolves the configured disk rather than a hardcoded one', function () {
    // Deliberately not the suite's own FILESYSTEM_DISK (pinned to `minio` in
    // phpunit.xml). If the service still hardcoded a disk name, it would write
    // to that one and this fake would see nothing.
    config(['filesystems.default' => 'local']);
    Storage::fake('local');

    // Sets CurrentTenant, which the service reads for its tenant path prefix.
    createTenant();

    $stored = app(FileStorageService::class)->uploadDataUrlImage(selfieDataUrl(120, 120), 'selfies');

    Storage::disk('local')->assertExists($stored['path']);
});

test('file storage follows the disk when the configuration changes', function () {
    // The mirror of the test above: same call, different configured disk, and
    // the bytes must land on the other one. A hardcoded literal cannot pass
    // both.
    config(['filesystems.default' => 'minio']);
    Storage::fake('local');
    Storage::fake('minio');

    // Sets CurrentTenant, which the service reads for its tenant path prefix.
    createTenant();

    $stored = app(FileStorageService::class)->uploadDataUrlImage(selfieDataUrl(120, 120), 'selfies');

    Storage::disk('minio')->assertExists($stored['path']);
    Storage::disk('local')->assertMissing($stored['path']);
});

/**
 * The health endpoint is what an uptime monitor watches. Reporting `unavailable`
 * for a cache that works is worse than reporting nothing: it trains whoever is
 * on call to ignore the one signal that matters.
 *
 * The suite runs CACHE_STORE=array and there is no Redis, so before the fix
 * these two keys were pinned at `unavailable` in every environment except one
 * that happened to run Redis.
 */
test('health endpoint reports the configured cache store as healthy', function () {
    $response = test()->getJson('/api/v1/health');

    $response->assertOk();

    expect($response->json('services.cache'))->toBe('healthy');
});

test('health endpoint reports the configured storage disk as healthy', function () {
    Storage::fake(config('filesystems.default'));

    $response = test()->getJson('/api/v1/health');

    $response->assertOk();

    expect($response->json('services.storage'))->toBe('healthy');
});

/**
 * `checkReadReplica` inspected `database.connections.mariadb.read` by name. On a
 * deployment running the `mysql` connection — or sqlite, as here — it asked
 * about a connection the application never opens, so it could not distinguish
 * "no replica configured" from "looking in the wrong place".
 */
test('read replica probe inspects the connection actually in use', function () {
    $connection = DB::connection()->getName();

    // The decoy must be a connection the application is NOT using, so it cannot
    // be hardcoded: under phpunit.mysql.xml the connection in use IS `mariadb`,
    // and the two config lines below then collided — the second undid the first
    // and the test asserted the opposite of its own intent. It passed on SQLite
    // by luck of the default connection's name.
    $decoy = $connection === 'mariadb' ? 'mysql' : 'mariadb';

    // The connection in use has no replica…
    config(["database.connections.{$connection}.read" => null]);
    // …while a connection the application is NOT using does. Reading the wrong
    // one therefore reports a replica that this deployment does not have, which
    // is precisely the state the hardcoded `mariadb` lookup produced.
    config(["database.connections.{$decoy}.read" => ['host' => ['replica.invalid']]]);

    expect(test()->getJson('/api/v1/health')->json('services.database_read'))
        ->toBe('not_configured');
});
