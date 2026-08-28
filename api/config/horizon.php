<?php

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
        'redis:payroll' => 60,
        'redis:default' => 60,
        'redis:sync' => 120,
        'redis:exports' => 180,
        'redis:mail' => 60,
        'redis:sms' => 60,
    ],

    /*
    |--------------------------------------------------------------------------
    | Job Trimming
    |--------------------------------------------------------------------------
    | recent_jobs:  minutes to keep completed jobs
    | failed_jobs:  minutes to keep failed jobs
    */
    'trim' => [
        'recent' => 60,
        'pending' => 60,
        'completed' => 60,
        'recent_failed' => 10080, // 7 days
        'failed' => 10080,
        'monitored' => 10080,
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
            'job' => 24,
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
    | Master Supervisor Memory Limit (MB)
    |--------------------------------------------------------------------------
    |
    | This is the MASTER supervisor's own limit, and it does not terminate
    | anything — MonitorMasterSupervisorMemory only logs "Memory limit exceeded"
    | when the master process passes it. It has no effect whatsoever on worker
    | processes, which take the per-supervisor `memory` option below (Horizon's
    | default is 128 MB when that option is omitted, which it was here).
    |
    | The old comment on this line read "terminate worker if exceeded", and
    | every container sizing decision made from it was wrong by roughly 2x in
    | one direction and 3x in the other: workers were assumed to be capped at
    | 256 MB when they were actually capped at 128, while payroll — which needs
    | more than either — was silently running at the 128 MB default and
    | recycling itself mid-run.
    |
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
    | Production is split across THREE environments, not one, because they map
    | to three separate containers (docker-compose.prod.yml: worker-realtime,
    | worker-notifications, worker-heavy). Two reasons this is not cosmetic:
    |
    |   1. Isolation. supervisor-payroll runs with `timeout: 1800`; a half-hour
    |      payroll job sharing a cgroup with supervisor-attendance — whose whole
    |      purpose is the 30s wait threshold declared above — starves live
    |      check-ins under exactly the month-end load where both matter.
    |   2. Memory. Every supervisor below declares an explicit `memory` (the
    |      per-worker cap, passed through as `--memory=`). They did not before,
    |      so all 23 possible processes silently ran at Horizon's 128 MB
    |      default — too little for payroll, and 2.9 GB of ceiling in total
    |      against the single 1 GB container that used to hold them all.
    |
    | Per-container ceilings, which is what the `memory` limits in
    | docker-compose.prod.yml are derived from (workers + ~256 MB for the master
    | supervisor process itself):
    |
    |   realtime       6x128 + 4x192 = 1536 + 256 = 1792 MB   (limit 2 GB)
    |   notifications  5x128 + 3x128 = 1024 + 256 = 1280 MB   (limit 1536 MB)
    |   heavy          3x384 + 2x256 = 1664 + 256 = 1920 MB   (limit 2 GB)
    |
    | Changing a `memory` or a `maxProcesses` below therefore changes what its
    | container must be allowed — keep the two in step, or the container is
    | OOM-killed by Docker before Horizon's own per-worker cap ever applies.
    |
    | Each container selects its set with `php artisan horizon --environment=`.
    |
    | TRAP: ProvisioningPlan::deploy() returns silently when the requested
    | environment matches no key here (vendor/laravel/horizon, `if (empty(
    | $supervisors)) return;`) — no error, no non-zero exit. A typo therefore
    | yields a Horizon container that starts, logs "Horizon started
    | successfully", reports healthy to `horizon:status` (which inspects every
    | master cluster-wide, not this one), and processes zero jobs forever. The
    | worker healthchecks in docker-compose.prod.yml each grep
    | `horizon:supervisors` for a supervisor name unique to that container
    | specifically to convert that silence into an unhealthy container. Renaming
    | a key here means renaming it in all three places: the `--environment` flag,
    | the healthcheck grep, and here.
    |
    */
    'environments' => [

        // Latency-sensitive. Anything an employee or a device is waiting on.
        'production-realtime' => [
            'supervisor-attendance' => [
                'connection' => 'redis',
                'queue' => ['attendance', 'devices'],
                'balance' => 'auto',
                'minProcesses' => 2,
                'maxProcesses' => 6,
                'tries' => 3,
                'timeout' => 30,
                'memory' => 128, // short jobs; a check-in payload is a few KB
                'nice' => 0,
            ],
            'supervisor-sync' => [
                'connection' => 'redis',
                'queue' => ['sync'],
                'balance' => 'auto',
                'minProcesses' => 1,
                'maxProcesses' => 4,
                'tries' => 3,
                'timeout' => 120,
                'memory' => 192, // offline batches carry many records per job
                'nice' => 5,
            ],
        ],

        // Fan-out. Slower than realtime and far more numerous, but nothing is
        // blocked on it, and a burst of it must not delay a check-in.
        'production-notifications' => [
            'supervisor-notifications' => [
                'connection' => 'redis',
                'queue' => ['notifications', 'mail', 'sms'],
                'balance' => 'auto',
                'minProcesses' => 2,
                'maxProcesses' => 5,
                'tries' => 3,
                'timeout' => 60,
                'memory' => 128, // rendering a mail/SMS body, nothing more
                'nice' => 5,
            ],
            'supervisor-default' => [
                'connection' => 'redis',
                'queue' => ['default'],
                'balance' => 'auto',
                'minProcesses' => 1,
                'maxProcesses' => 3,
                'tries' => 3,
                'timeout' => 90,
                'memory' => 128, // Horizon's default, stated rather than implied
                'nice' => 10,
            ],
        ],

        // Long, memory-hungry, and allowed to be. These two are the reason the
        // split exists: `nice` alone cannot buy back RAM, only CPU priority.
        'production-heavy' => [
            'supervisor-payroll' => [
                'connection' => 'redis',
                'queue' => ['payroll'],
                'balance' => 'simple',
                'minProcesses' => 1,
                'maxProcesses' => 3,
                'tries' => 2,
                'timeout' => 1800,
                'memory' => 384, // holds a tenant's period; the 128 default recycled it mid-run
                'nice' => 10,
            ],
            'supervisor-exports' => [
                'connection' => 'redis',
                'queue' => ['exports'],
                'balance' => 'simple',
                'minProcesses' => 1,
                'maxProcesses' => 2,
                'tries' => 2,
                'timeout' => 600,
                'memory' => 256, // builds a CSV/PDF in memory before writing
                'nice' => 15,
            ],
        ],

        // One container, every queue — see the `worker` service in
        // docker-compose.lowmem.yml. Not the same shape as 'local' below despite
        // looking similar: this runs in production, with real traffic, on a host
        // (4 vCPU / 4 GB) too small for the three-container split above, so
        // unlike 'local' it declares explicit `memory` (Horizon's 128 MB default
        // is wrong for payroll here for the same reason it was wrong in
        // production-heavy) and a `timeout` long enough to cover payroll's
        // worst case, because that queue now shares a pool instead of owning
        // an isolated container.
        //
        // Budget, matched to the `worker` service's 768 MB limit in
        // docker-compose.lowmem.yml: maxProcesses 2 x memory 384 MB = 768 MB,
        // + ~60-80 MB for the artisan/Horizon master process itself. That
        // leaves no slack — do not raise maxProcesses without raising the
        // container limit by the same amount, same rule as production-heavy.
        //
        // Trade-off worth knowing before relying on this tier: with only 2
        // workers shared across all 9 queues, a payroll run (timeout 1800s)
        // can occupy one of them for up to 30 minutes, halving real-time
        // capacity for attendance/devices for that whole window. That is the
        // cost of 4 GB, not a bug. Move to docker-compose.prod.yml's
        // three-container split once the host has the 8 GB
        // docs/DEPLOYMENT.md documents as the minimum for that topology.
        'production-lowmem' => [
            'supervisor-lowmem' => [
                'connection' => 'redis',
                'queue' => ['attendance', 'devices', 'sync', 'notifications', 'mail', 'sms', 'default', 'payroll', 'exports'],
                'balance' => 'auto',
                'minProcesses' => 1,
                'maxProcesses' => 2,
                'tries' => 3,
                'timeout' => 1800, // must cover payroll, the longest job this pool runs
                'memory' => 384,   // sized for payroll; over-provisioned for everything else in the pool
                'nice' => 5,
            ],
        ],

        // One container, every queue — see the `worker` service in
        // docker-compose.yml. The production split is deliberately NOT mirrored
        // here: three worker containers on a developer's machine costs ~1.5 GB
        // to reproduce a contention problem that only exists under load.
        'local' => [
            'supervisor-local' => [
                'connection' => 'redis',
                'queue' => ['attendance', 'devices', 'payroll', 'notifications', 'mail', 'sms', 'exports', 'sync', 'default'],
                'balance' => 'simple',
                'minProcesses' => 1,
                'maxProcesses' => 4,
                'tries' => 3,
                'timeout' => 300,
                'nice' => 0,
            ],
        ],
    ],
];
