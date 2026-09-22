<?php

return [

    /*
    |--------------------------------------------------------------------------
    | HTTP-driven scheduler and queue runner
    |--------------------------------------------------------------------------
    |
    | Shared hosting on this account offers no Scheduled Tasks section at all
    | (G0-D = FAIL, docs/deployment/GATE-0-RESULT.md), so neither
    | `schedule:run` nor `queue:work` has a cron to start it. These routes are
    | the documented fallback: anything that can fetch a URL on a timer — a
    | Plesk "Fetch a URL" task, an uptime monitor, a GitHub Actions cron, a
    | laptop — can drive them.
    |
    | FAIL CLOSED. With no token set the routes 404. They are not registered
    | as "forbidden", they are not registered at all, so an unconfigured
    | deployment does not advertise that they exist.
    |
    */

    'token' => env('CRON_TOKEN'),

    /*
    | The token must be long enough that an online guess is hopeless even at
    | the rate limit. Anything shorter is refused at boot rather than quietly
    | accepted — a 6-character CRON_TOKEN is worse than none, because it looks
    | configured.
    */
    'min_token_length' => 32,

    /*
    | Wall-clock ceiling for one queue drain. Kept under a typical 60s HTTP
    | timeout so the caller gets a real response rather than a gateway error,
    | and under the one-minute tick so two runs never overlap.
    */
    'queue_max_seconds' => (int) env('CRON_QUEUE_MAX_SECONDS', 50),

    /*
    | How long the overlap lock is held. A fetch-a-URL task that fires while
    | the previous run is still going gets 409, not a second worker.
    */
    'lock_seconds' => (int) env('CRON_LOCK_SECONDS', 110),
];
