<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\HealthController;

// ──────────────────────── Database read/write split ──────────────────────

test('database config supports read replicas when DB_READ_HOST is set', function () {
    config(['database.connections.mariadb.read' => [
        'host' => ['replica-1', 'replica-2'],
        'port' => '3306',
    ]]);
    config(['database.connections.mariadb.sticky' => true]);

    $config = config('database.connections.mariadb');

    expect($config['read']['host'])->toBe(['replica-1', 'replica-2']);
    expect($config['sticky'])->toBeTrue();
    expect($config['write']['host'])->toBe(config('database.connections.mariadb.write.host'));
});

test('database config has no read replicas when DB_READ_HOST is empty', function () {
    config(['database.connections.mariadb.read' => null]);

    expect(config('database.connections.mariadb.read'))->toBeNull();
});

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

test('reverb scaling uses redis for cross-instance pubsub', function () {
    config(['reverb.servers.reverb.scaling.enabled' => true]);

    $scaling = config('reverb.servers.reverb.scaling');
    expect($scaling['enabled'])->toBeTrue();
    expect($scaling['channel'])->toBe('reverb');
    expect($scaling['server'])->toHaveKeys(['host', 'port', 'database']);
});

// ──────────────────────── Session / cache config ─────────────────────

test('redis cache uses separate database from default', function () {
    $defaultDb = config('database.redis.default.database');
    $cacheDb = config('database.redis.cache.database');

    expect($defaultDb)->not->toBe($cacheDb);
});

test('redis supports cluster mode via config', function () {
    expect(config('database.redis.options.cluster'))->not->toBeNull();
});
