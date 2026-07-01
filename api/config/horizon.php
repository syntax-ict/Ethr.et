<?php

use Laravel\Horizon\Listeners\TrimRecentJobs;
use Laravel\Horizon\Listeners\TrimFailedJobs;

return [

    /*
    |--------------------------------------------------------------------------
    | Horizon Domain
    |--------------------------------------------------------------------------
    */
    'domain' => env('HORIZON_DOMAIN', null),

    /*
    |--------------------------------------------------------------------------
    | Horizon Path
    |--------------------------------------------------------------------------
    */
    'path' => env('HORIZON_PATH', 'horizon'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Redis Connection
    |--------------------------------------------------------------------------
    */
    'use' => 'default',

    /*
    |--------------------------------------------------------------------------
    | Horizon Redis Prefix
    |--------------------------------------------------------------------------
    */
    'prefix' => env('HORIZON_PREFIX', 'horizon:'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Route Middleware
    |--------------------------------------------------------------------------
    */
    'middleware' => ['web'],

    /*
    |--------------------------------------------------------------------------
    | Queue Wait Time Thresholds (seconds)
    |--------------------------------------------------------------------------
    */
    'waits' => [
        'redis:attendance' => 30,
        'redis:payroll'    => 60,
        'redis:default'    => 60,
        'redis:sync'       => 120,
        'redis:exports'    => 180,
        'redis:mail'       => 60,
        'redis:sms'        => 60,
    ],

    /*
    |--------------------------------------------------------------------------
    | Job Trimming
    |--------------------------------------------------------------------------
    | recent_jobs:  minutes to keep completed jobs
    | failed_jobs:  minutes to keep failed jobs
    */
    'trim' => [
        'recent'           => 60,
        'pending'          => 60,
        'completed'        => 60,
        'recent_failed'    => 10080, // 7 days
        'failed'           => 10080,
        'monitored'        => 10080,
    ],

    /*
    |--------------------------------------------------------------------------
    | Silenced Jobs (not shown in Horizon UI)
    |--------------------------------------------------------------------------
    */
    'silenced' => [],

    /*
    |--------------------------------------------------------------------------
    | Metrics (seconds between snapshot)
    |--------------------------------------------------------------------------
    */
    'metrics' => [
        'trim_snapshots' => [
            'job'   => 24,
            'queue' => 24,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Fast Termination
    |--------------------------------------------------------------------------
    */
    'fast_termination' => false,

    /*
    |--------------------------------------------------------------------------
    | Memory Limit (MB) — terminate worker if exceeded
    |--------------------------------------------------------------------------
    */
    'memory_limit' => 256,

    /*
    |--------------------------------------------------------------------------
    | Queue Worker Configuration
    |--------------------------------------------------------------------------
    |
    | attendance  — real-time check-ins, biometric sync (fast, high priority)
    | devices     — device polling, webhook processing
    | payroll     — payroll run calculations (slow, resource-heavy)
    | notifications — email, SMS, in-app push
    | exports     — CSV/PDF report generation
    | sync        — offline attendance sync, conflict resolution
    | mail        — transactional email
    | default     — everything else
    |
    */
    'environments' => [
        'production' => [
            'supervisor-attendance' => [
                'connection'   => 'redis',
                'queue'        => ['attendance', 'devices'],
                'balance'      => 'auto',
                'minProcesses' => 2,
                'maxProcesses' => 6,
                'tries'        => 3,
                'timeout'      => 30,
                'nice'         => 0,
            ],
            'supervisor-payroll' => [
                'connection'   => 'redis',
                'queue'        => ['payroll'],
                'balance'      => 'simple',
                'minProcesses' => 1,
                'maxProcesses' => 3,
                'tries'        => 2,
                'timeout'      => 1800,
                'nice'         => 10,
            ],
            'supervisor-notifications' => [
                'connection'   => 'redis',
                'queue'        => ['notifications', 'mail', 'sms'],
                'balance'      => 'auto',
                'minProcesses' => 2,
                'maxProcesses' => 5,
                'tries'        => 3,
                'timeout'      => 60,
                'nice'         => 5,
            ],
            'supervisor-exports' => [
                'connection'   => 'redis',
                'queue'        => ['exports'],
                'balance'      => 'simple',
                'minProcesses' => 1,
                'maxProcesses' => 2,
                'tries'        => 2,
                'timeout'      => 600,
                'nice'         => 15,
            ],
            'supervisor-sync' => [
                'connection'   => 'redis',
                'queue'        => ['sync'],
                'balance'      => 'auto',
                'minProcesses' => 1,
                'maxProcesses' => 4,
                'tries'        => 3,
                'timeout'      => 120,
                'nice'         => 5,
            ],
            'supervisor-default' => [
                'connection'   => 'redis',
                'queue'        => ['default'],
                'balance'      => 'auto',
                'minProcesses' => 1,
                'maxProcesses' => 3,
                'tries'        => 3,
                'timeout'      => 90,
                'nice'         => 10,
            ],
        ],

        'local' => [
            'supervisor-local' => [
                'connection'   => 'redis',
                'queue'        => ['attendance', 'devices', 'payroll', 'notifications', 'mail', 'sms', 'exports', 'sync', 'default'],
                'balance'      => 'simple',
                'minProcesses' => 1,
                'maxProcesses' => 4,
                'tries'        => 3,
                'timeout'      => 300,
                'nice'         => 0,
            ],
        ],
    ],
];
