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
    | Wall-clock ceiling for one queue drain. Kept under the 30s request
    | timeout free URL-fetch cron services allow, so the caller gets a real
    | response rather than recording a timeout, and under the one-minute tick
    | so two runs never overlap. It was 50 until 2026-10-12, sized for the
    | GitHub caller alone (console QA F23).
    */
    'queue_max_seconds' => (int) env('CRON_QUEUE_MAX_SECONDS', 25),

    /*
    | How long the overlap lock is held. A fetch-a-URL task that fires while
    | the previous run is still going gets 409, not a second worker.
    */
    'lock_seconds' => (int) env('CRON_LOCK_SECONDS', 110),

    /*
    | Background work after ordinary requests (RunBackgroundWorkAfterResponse).
    | The external timer fires only a few times a day on this host (F23), so
    | any request that reaches Laravel may, at most once a minute, drain the
    | queue for a few seconds and run the scheduler catch-up when its heartbeat
    | is stale — after the response has been sent, so nobody waits for it.
    | The cron endpoints stay; this only stops the queue depending on them.
    */
    'opportunistic' => (bool) env('CRON_OPPORTUNISTIC', true),
    'opportunistic_seconds' => (int) env('CRON_OPPORTUNISTIC_SECONDS', 5),
    'opportunistic_every_seconds' => (int) env('CRON_OPPORTUNISTIC_EVERY_SECONDS', 60),
    'opportunistic_schedule_after_seconds' => (int) env('CRON_OPPORTUNISTIC_SCHEDULE_AFTER_SECONDS', 300),
    // Only when the response can be sent before the work starts (PHP-FPM,
    // LiteSpeed). Under plain CGI the visitor would wait for the drain.
    'opportunistic_requires_early_flush' => (bool) env('CRON_OPPORTUNISTIC_REQUIRES_EARLY_FLUSH', true),
];
