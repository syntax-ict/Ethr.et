<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\HealthController;

// ──────────────────────── Database read/write split ──────────────────────
//
// Two tests stood here that set `database.connections.mariadb.read` and then
// asserted the value they had just set. They could not fail, so they measured
// nothing. The meaningful questions are the shipped default below and the
// health probe further down, which reads the real config.

test('sticky sessions enabled by default', function () {
    expect(config('database.connections.mariadb.sticky'))->toBeTrue();
});

// ──────────────────────── Health endpoint ─────────────────────────────

test('health controller has read replica check method', function () {
    $controller = new HealthController;
    $method = new ReflectionMethod($controller, 'checkReadReplica');

    expect($method->getReturnType()->getName())->toBe('string');
});

test('read replica check returns not_configured when no replica', function () {
    config(['database.connections.mariadb.read' => null]);

    $controller = new HealthController;
    $method = new ReflectionMethod($controller, 'checkReadReplica');

    expect($method->invoke($controller))->toBe('not_configured');
});

// ──────────────────────── Reverb scaling config ──────────────────────

test('reverb scaling config defaults to disabled', function () {
    expect(config('reverb.servers.reverb.scaling.enabled'))->toBeFalse();
});

// ──────────────────────── No Redis on the target ─────────────────────
//
// Two tests stood here asserting a Redis cache database separate from the
// default one and a Redis cluster option. Both described the VPS stack, whose
// two-instance Redis layout config/database.php carried until 2026-09-30.
//
// What replaces them is NOT "no redis config exists". Laravel merges the
// framework's own config underneath these files, so deleting a `redis` block
// here only hands the key back to the framework default — measured: all three
// keys still resolve after the deletion. A redis entry nobody selects is inert.
// The thing that must not drift is which driver is SELECTED, and that is set in
// two places: the fallback in each config file, and the template developers copy.

test('every driver falls back to what the shared-hosting target runs', function (string $file, string $pattern) {
    $source = (string) file_get_contents(config_path($file));

    expect($source)->toMatch($pattern);
})->with([
    'cache' => ['cache.php', "/'default' => env\\('CACHE_STORE', 'database'\\)/"],
    'queue' => ['queue.php', "/'default' => env\\('QUEUE_CONNECTION', 'database'\\)/"],
    'session' => ['session.php', "/'driver' => env\\('SESSION_DRIVER', 'database'\\)/"],
    'files' => ['filesystems.php', "/'default' => env\\('FILESYSTEM_DISK', 'local'\\)/"],
]);

test('the development template selects no redis and no minio', function () {
    $example = (string) file_get_contents(base_path('.env.example'));

    expect($example)
        ->toMatch('/^CACHE_STORE=database$/m')
        ->toMatch('/^QUEUE_CONNECTION=database$/m')
        ->toMatch('/^SESSION_DRIVER=database$/m')
        ->toMatch('/^FILESYSTEM_DISK=local$/m')
        ->not->toMatch('/^(REDIS|MINIO)_[A-Z_]+=/m');
});
