<?php

declare(strict_types=1);

return [

    // `?? 'null'` is load-bearing, not defensive noise.
    //
    // A `null` connection is defined below, so BROADCAST_CONNECTION=null reads
    // as the obvious way to turn broadcasting off — and it is exactly what the
    // shared-hosting deployment wants, since Reverb needs a WebSocket server no
    // shared tier provides.
    //
    // But Laravel's env() parses the literal string "null" into PHP null and
    // returns it *instead of* the default, so `default` became null rather than
    // 'null'. BroadcastManager then threw "Broadcast connection [] is not
    // defined" on the first broadcast — a runtime failure produced by writing
    // down precisely what the config appears to invite.
    //
    // Coalescing back to the 'null' driver honours the intent. `log` remains
    // the better choice for a first deployment: it satisfies the twelve
    // notification classes that guard on
    // `config('broadcasting.default') === 'reverb'` just as 'null' does, and it
    // leaves a trace when something tries to broadcast.
    // Default changed from 'reverb' to 'null', 2026-09-25. Reverb needs a
    // long-running daemon; defaulting to it meant a checkout with no `.env`
    // built a Reverb broadcaster with a null Pusher key at `package:discover`
    // time, and `composer install` exited 1 before any gate ran. That is
    // cause 2 of the five structural CI failures in the root `CLAUDE.md`, and
    // it forced a workflow-level override that this change removes.
    //
    // Nothing that actually runs is affected: docker-compose.yml:39,
    // .env.example and .env.shared-hosting.example all set the value
    // explicitly. The default only ever served the no-`.env` path, where
    // `reverb` was the one value guaranteed to fail.
    //
    // The `?? 'null'` stays and is load-bearing: Laravel's env() converts the
    // literal string "null" to PHP null, so an explicit BROADCAST_CONNECTION=null
    // would otherwise fall through to no driver at all.
    'default' => env('BROADCAST_CONNECTION', 'null') ?? 'null',

    'connections' => [

        'reverb' => [
            'driver' => 'reverb',
            'key' => env('REVERB_APP_KEY'),
            'secret' => env('REVERB_APP_SECRET'),
            'app_id' => env('REVERB_APP_ID'),
            'options' => [
                'host' => env('REVERB_HOST', 'localhost'),
                'port' => env('REVERB_PORT', 8080),
                'scheme' => env('REVERB_SCHEME', 'http'),
                'useTLS' => env('REVERB_SCHEME', 'http') === 'https',
            ],
            'client_options' => [],
        ],

        'pusher' => [
            'driver' => 'pusher',
            'key' => env('PUSHER_APP_KEY'),
            'secret' => env('PUSHER_APP_SECRET'),
            'app_id' => env('PUSHER_APP_ID'),
            'options' => [
                'cluster' => env('PUSHER_APP_CLUSTER'),
                'host' => env('PUSHER_HOST') ?: 'api-'.env('PUSHER_APP_CLUSTER', 'mt1').'.pusher.com',
                'port' => env('PUSHER_PORT', 443),
                'scheme' => env('PUSHER_SCHEME', 'https'),
                'encrypted' => true,
                'useTLS' => env('PUSHER_SCHEME', 'https') === 'https',
            ],
            'client_options' => [],
        ],

        'log' => [
            'driver' => 'log',
        ],

        'null' => [
            'driver' => 'null',
        ],

    ],

];
