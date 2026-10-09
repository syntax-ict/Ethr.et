<?php

return [

    /*
    |--------------------------------------------------------------------------
    | HTTP-driven install and release steps
    |--------------------------------------------------------------------------
    |
    | The production host gives artisan no way to run: SSH is forbidden, Plesk
    | Git's deployment action runs in a chroot with no PHP, the account has no
    | Scheduled Tasks, and the Laravel Toolkit attaches no application
    | ("Attached 0 application(s)", 2026-10-09). These routes run a FIXED list
    | of release steps over HTTP instead. See
    | docs/decisions/OWNER-DECISION-MAINTENANCE-ENDPOINT.md.
    |
    | FAIL CLOSED. With no token set the routes 404, so an unconfigured
    | deployment does not advertise that they exist. Set the token for a
    | release, and empty it again afterwards.
    |
    | Its own secret, deliberately not CRON_TOKEN: that one sits in GitHub's
    | secrets and is sent every five minutes; this one can migrate the
    | database and create a platform administrator.
    |
    */

    'token' => env('MAINTENANCE_TOKEN'),

    /*
    | Read MAINTENANCE_TOKEN from the .env file on each request instead of from
    | config. null = only when the configuration is cached, which is when
    | config() would otherwise keep a token that .env no longer has.
    */
    'read_env_file' => null,

    'min_token_length' => 32,

    /*
    | Attempts per minute per client address, counted BEFORE the token is
    | compared, so a wrong guess spends the budget too.
    */
    'attempts_per_minute' => (int) env('MAINTENANCE_ATTEMPTS_PER_MINUTE', 5),

    /*
    | The cache store for that counter and for the one-run-at-a-time lock.
    | `file`, never the default: on a fresh install the default store is the
    | `database` one, and its `cache` table does not exist until `migrate` has
    | run — the very step these routes exist to run.
    */
    'cache_store' => env('MAINTENANCE_CACHE_STORE', 'file'),

    'lock_seconds' => (int) env('MAINTENANCE_LOCK_SECONDS', 300),
];
