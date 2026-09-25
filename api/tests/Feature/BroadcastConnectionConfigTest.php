<?php

declare(strict_types=1);

use Illuminate\Broadcasting\BroadcastManager;

/**
 * BROADCAST_CONNECTION must survive being set to the string "null".
 *
 * A `null` connection is defined in config/broadcasting.php, so writing
 * `BROADCAST_CONNECTION=null` reads as the obvious way to turn broadcasting
 * off — and turning it off is what the shared-hosting deployment needs, because
 * Reverb requires a WebSocket server no shared tier provides.
 *
 * Laravel's env() parses the literal "null" into PHP null and returns it
 * *instead of* the default, so `broadcasting.default` resolved to null rather
 * than 'null' and BroadcastManager threw "Broadcast connection [] is not
 * defined" on the first broadcast. A runtime failure produced by writing down
 * exactly what the configuration appears to invite.
 *
 * These tests pin the behaviour of the fix, and of the twelve notification
 * classes that decide whether to add a `broadcast` channel by comparing
 * `config('broadcasting.default')` against the string 'reverb'. Those
 * comparisons are why any disabled value must remain a string: null would
 * compare false too, but only after the manager had already thrown.
 */
it('resolves a defined connection when BROADCAST_CONNECTION is literally "null"', function () {
    // The real trap, exercised rather than described. Laravel's Env reads
    // through adapters that see $_SERVER live, so setting it here and
    // re-evaluating the config file runs the actual expression under the actual
    // condition: env() parses "null" and hands back PHP null.
    $original = $_SERVER['BROADCAST_CONNECTION'] ?? null;
    $_SERVER['BROADCAST_CONNECTION'] = 'null';

    try {
        $resolved = require config_path('broadcasting.php');

        // Without the `?? 'null'` coalesce this is PHP null, and
        // BroadcastManager throws "Broadcast connection [] is not defined" on
        // the first broadcast.
        expect($resolved['default'])->not->toBeNull(
            'BROADCAST_CONNECTION=null resolved to PHP null — the coalesce in config/broadcasting.php is gone.'
        );

        // toHaveKey's second argument is an expected VALUE, not a message, so
        // the assertion is kept bare and the explanation stays in the comment:
        // broadcasting.default must name a connection that is actually defined.
        expect($resolved['connections'])->toHaveKey($resolved['default']);
    } finally {
        if ($original === null) {
            unset($_SERVER['BROADCAST_CONNECTION']);
        } else {
            $_SERVER['BROADCAST_CONNECTION'] = $original;
        }
    }
});

it('does not throw when the default connection is the null driver', function () {
    config(['broadcasting.default' => 'null']);

    $manager = app(BroadcastManager::class);

    expect(fn () => $manager->connection())->not->toThrow(InvalidArgumentException::class);
});

it('throws for a genuinely undefined connection, so the coalesce is not hiding real mistakes', function () {
    config(['broadcasting.default' => 'a-driver-that-does-not-exist']);

    expect(fn () => app(BroadcastManager::class)->connection())
        ->toThrow(InvalidArgumentException::class);
});

it('keeps every disabled value a string, because notifications compare against one', function () {
    // RespectsNotificationPreferences and eleven notification classes do
    // `config('broadcasting.default') === 'reverb'`. A non-string default would
    // still compare false, but only after BroadcastManager had already thrown
    // while resolving — so the comparison never gets to run.
    foreach (['log', 'null', 'reverb'] as $connection) {
        config(['broadcasting.default' => $connection]);

        expect(config('broadcasting.default'))->toBeString();
        expect(config("broadcasting.connections.{$connection}"))->not->toBeNull(
            "broadcasting.connections.{$connection} is not defined, so this value cannot be used to disable broadcasting."
        );
    }
});

/**
 * The *unset* case, which is the one that cost fifty CI runs.
 *
 * `broadcasting.default` used to read `env('BROADCAST_CONNECTION', 'reverb')`.
 * A fresh checkout has no `.env`, `routes/channels.php` calls
 * Broadcast::channel() at load time, and `package:discover` runs inside
 * `composer install` — so the install itself exited 1 building a Reverb
 * broadcaster with a null Pusher key, before any gate could run. Three
 * workflows carried a `BROADCAST_CONNECTION: "null"` override purely to work
 * around it; the default is now `null` and the overrides are gone, so this test
 * is the thing that keeps them gone.
 *
 * It asserts the property, not the literal: the default must name a connection
 * that is defined and that needs no running daemon. `log` would pass too.
 */
it('falls back to a daemon-free connection when BROADCAST_CONNECTION is unset', function () {
    $originalServer = $_SERVER['BROADCAST_CONNECTION'] ?? null;
    $originalEnv = $_ENV['BROADCAST_CONNECTION'] ?? null;
    $originalPutenv = getenv('BROADCAST_CONNECTION');

    // All three adapters, because Laravel's Env repository reads $_SERVER,
    // $_ENV and putenv(). Clearing one and leaving another set would make this
    // test pass for the wrong reason.
    unset($_SERVER['BROADCAST_CONNECTION'], $_ENV['BROADCAST_CONNECTION']);
    putenv('BROADCAST_CONNECTION');

    try {
        $resolved = require config_path('broadcasting.php');

        expect($resolved['default'])->toBeString();
        expect($resolved['connections'])->toHaveKey($resolved['default']);

        // reverb and pusher both need a server this repository does not run in
        // CI, on shared hosting, or on a fresh clone. Either of them here
        // reintroduces the defect.
        expect($resolved['default'])->not->toBeIn(['reverb', 'pusher']);
    } finally {
        if ($originalServer !== null) {
            $_SERVER['BROADCAST_CONNECTION'] = $originalServer;
        }
        if ($originalEnv !== null) {
            $_ENV['BROADCAST_CONNECTION'] = $originalEnv;
        }
        if ($originalPutenv !== false) {
            putenv('BROADCAST_CONNECTION='.$originalPutenv);
        }
    }
});
