<?php

return [

    /*
    |--------------------------------------------------------------------------
    | How much of one request a payroll run may use
    |--------------------------------------------------------------------------
    |
    | Queued work on the shared host runs inside a web request (the cron
    | endpoint, and the drain after a page response), and the host stops a
    | request after `max_execution_time` seconds of CPU: 30 on the account.
    | ProcessPayrollJob stops after this many seconds of the request, having
    | written a batch, and hands the rest of the run to a new job. Null is
    | half of `max_execution_time`; where that is 0 (a command-line worker) a
    | run is never cut short.
    |
    */
    'request_budget_seconds' => env('PAYROLL_REQUEST_BUDGET_SECONDS') !== null
        ? (float) env('PAYROLL_REQUEST_BUDGET_SECONDS')
        : null,

];
