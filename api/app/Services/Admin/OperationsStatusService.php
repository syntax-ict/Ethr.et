<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Http\Middleware\VerifyMaintenanceToken;
use App\Services\Observability\QueueHealth;
use App\Support\Mail\MailHealth;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The console's answer to "is ETHR doing its work right now?" — the
 * operations centre, phase 1 (docs/operations/OPERATIONS-CENTRE-SPEC.md).
 *
 * Phase 1 shows what the platform already records and the console did not:
 * the scheduler heartbeat and which caller kept it alive, how long the oldest
 * job has waited, whether mail is leaving, and whether the deployed release
 * finished its release steps. It records nothing new beyond the two caller
 * ticks in QueueHealth.
 *
 * Every area has a state of `ok`, `warning`, `critical` or `unknown`, and
 * `unknown` is never red: a fresh install with no heartbeat yet, or a value
 * this host cannot read, is not a fault. Panels that were always red trained
 * operators to ignore them here twice (a hard-coded `minio` disk and a
 * `redis` row on a host without Redis). Reasons are codes, not sentences, so
 * the console translates them.
 *
 * Nothing here reads a tenant-owned table: `jobs`, `failed_jobs` and the
 * migration repository are global, so no tenant scope is bypassed.
 */
final class OperationsStatusService
{
    public const OK = 'ok';

    public const WARNING = 'warning';

    public const CRITICAL = 'critical';

    public const UNKNOWN = 'unknown';

    /** Worst first wins; `unknown` sits below `warning` so it never raises an alarm. */
    private const RANK = [self::OK => 0, self::UNKNOWN => 1, self::WARNING => 2, self::CRITICAL => 3];

    /** The scheduler runs every five minutes at most; red agrees with QueueHealth::snapshot(). */
    public const SCHEDULER_WARNING_SECONDS = 600;

    public const SCHEDULER_CRITICAL_SECONDS = 900;

    /** The external caller fires every five minutes; two hours of silence means it has stopped. */
    public const EXTERNAL_WARNING_SECONDS = 1800;

    public const EXTERNAL_CRITICAL_SECONDS = 7200;

    /** Red agrees with the oldest-job threshold in QueueHealth::snapshot(). */
    public const QUEUE_WARNING_SECONDS = 900;

    public const QUEUE_CRITICAL_SECONDS = 1800;

    public function __construct(
        private readonly SystemHealthService $system,
        private readonly QueueHealth $queues,
    ) {}

    /**
     * @return array{
     *     status: 'ok'|'warning'|'critical'|'unknown',
     *     checked_at: string,
     *     background: array{state: 'ok'|'warning'|'critical'|'unknown', reasons: list<string>, scheduler: array{last_run_at: string|null, age_seconds: int|null, state: 'ok'|'warning'|'critical'|'unknown'}, external_caller: array{last_run_at: string|null, age_seconds: int|null, state: 'ok'|'warning'|'critical'|'unknown'}, page_traffic: array{last_run_at: string|null, age_seconds: int|null}, last_drain_at: string|null, queues: list<array{name: string, depth: int|null, oldest_waiting_seconds: int|null, state: 'ok'|'warning'|'critical'|'unknown'}>},
     *     failed_jobs: array{state: 'ok'|'warning'|'critical'|'unknown', reasons: list<string>, count: int|null, last_failed_at: string|null},
     *     delivery: array{state: 'ok'|'warning'|'critical'|'unknown', reasons: list<string>, mail: array{mailer: string, last_sent_at: string|null, last_failed_at: string|null, tls: string|null}},
     *     release: array{state: 'ok'|'warning'|'critical'|'unknown', reasons: list<string>, environment: string, commit: string|null, built_at: string|null, pending_migrations: int|null, config_cached_at: string|null, routes_cached_at: string|null, debug: bool, queue_connection: string, broadcast_connection: string, maintenance_routes_open: bool, cron_token_set: bool, php: array{version: string, upload_max_filesize: string, post_max_size: string, memory_limit: string, max_execution_time: string}},
     *     services: array{state: 'ok'|'warning'|'critical'|'unknown', reasons: list<string>, checks: list<array{name: string, status: string, response_ms: int|null}>, disk_free_gb: float|null}
     * }
     */
    public function status(): array
    {
        $background = $this->background();
        $failedJobs = $this->failedJobs();
        $delivery = $this->delivery();
        $release = $this->release();
        $services = $this->services();

        return [
            'status' => $this->worst([
                $background['state'],
                $failedJobs['state'],
                $delivery['state'],
                $release['state'],
                $services['state'],
            ]),
            'checked_at' => now()->toIso8601String(),
            'background' => $background,
            'failed_jobs' => $failedJobs,
            'delivery' => $delivery,
            'release' => $release,
            'services' => $services,
        ];
    }

    /**
     * Scheduler heartbeat, the two callers that keep it alive, and how long
     * the oldest job on each queue has waited.
     *
     * The external caller is reported apart from the heartbeat because page
     * views run the scheduler too (RunBackgroundWorkAfterResponse): with people
     * signed in, the heartbeat stays fresh while the external timer is dead,
     * and nothing but that timer runs the night's backup.
     */
    private function background(): array
    {
        $reasons = [];

        $scheduler = $this->moment($this->queues->lastBeat(), self::SCHEDULER_WARNING_SECONDS, self::SCHEDULER_CRITICAL_SECONDS);
        if ($scheduler['last_run_at'] === null) {
            $reasons[] = 'scheduler_never_run';
        } elseif ($scheduler['state'] !== self::OK) {
            $reasons[] = 'scheduler_stale';
        }

        $external = $this->moment($this->queues->lastExternalTick(), self::EXTERNAL_WARNING_SECONDS, self::EXTERNAL_CRITICAL_SECONDS);
        if ($external['last_run_at'] === null) {
            $reasons[] = 'external_caller_never_seen';
        } elseif ($external['state'] !== self::OK) {
            $reasons[] = 'external_caller_stale';
        }

        $traffic = $this->queues->lastTrafficTick();

        $queues = [];
        foreach (QueueHealth::QUEUES as $name) {
            $queue = $this->queue($name);
            if ($queue['state'] === self::WARNING || $queue['state'] === self::CRITICAL) {
                $reasons[] = 'queue_waiting';
            }
            $queues[] = $queue;
        }

        return [
            'state' => $this->worst([$scheduler['state'], $external['state'], ...array_column($queues, 'state')]),
            'reasons' => array_values(array_unique($reasons)),
            'scheduler' => $scheduler,
            'external_caller' => $external,
            'page_traffic' => [
                'last_run_at' => $traffic?->toIso8601String(),
                'age_seconds' => $this->age($traffic),
            ],
            'last_drain_at' => $this->queues->lastDrain()?->toIso8601String(),
            'queues' => $queues,
        ];
    }

    /**
     * @return array{name: string, depth: int|null, oldest_waiting_seconds: int|null, state: string}
     */
    private function queue(string $name): array
    {
        try {
            $depth = (int) DB::table('jobs')->where('queue', $name)->count();
            $oldest = DB::table('jobs')->where('queue', $name)->whereNull('reserved_at')->min('available_at');
        } catch (Throwable) {
            return ['name' => $name, 'depth' => null, 'oldest_waiting_seconds' => null, 'state' => self::UNKNOWN];
        }

        $waited = $oldest === null ? null : max(0, time() - (int) $oldest);

        return [
            'name' => $name,
            'depth' => $depth,
            'oldest_waiting_seconds' => $waited,
            'state' => $this->byAge($waited, self::QUEUE_WARNING_SECONDS, self::QUEUE_CRITICAL_SECONDS, self::OK),
        ];
    }

    /**
     * A failed job ran and broke, which is worth a look but never an alarm on
     * its own: nothing-ran is the alarm, and that is the background area's.
     */
    private function failedJobs(): array
    {
        try {
            $count = (int) DB::table('failed_jobs')->count();
            $last = DB::table('failed_jobs')->max('failed_at');
        } catch (Throwable) {
            return ['state' => self::UNKNOWN, 'reasons' => ['failed_jobs_unreadable'], 'count' => null, 'last_failed_at' => null];
        }

        return [
            'state' => $count > 0 ? self::WARNING : self::OK,
            'reasons' => $count > 0 ? ['failed_jobs_present'] : [],
            'count' => $count,
            'last_failed_at' => $last === null ? null : Carbon::parse((string) $last)->toIso8601String(),
        ];
    }

    /**
     * Mail is red only when the last failure is later than the last success:
     * the signature of the SMTP certificate or credentials going bad, which is
     * how mail broke here before. One failure followed by a send is history.
     */
    private function delivery(): array
    {
        $mailer = (string) config('mail.default');
        $mail = MailHealth::snapshot();
        $sent = $mail['last_sent_at'];
        $failed = $mail['last_failed_at'];

        if (in_array($mailer, ['log', 'array'], true)) {
            $state = self::UNKNOWN;
            $reasons = ['mail_not_sent_by_this_mailer'];
        } elseif ($sent === null && $failed === null) {
            $state = self::UNKNOWN;
            $reasons = ['mail_no_record'];
        } elseif ($failed !== null && ($sent === null || Carbon::parse($failed)->greaterThan(Carbon::parse($sent)))) {
            $state = self::CRITICAL;
            $reasons = ['mail_failing'];
        } else {
            $state = self::OK;
            $reasons = [];
        }

        return [
            'state' => $state,
            'reasons' => $reasons,
            'mail' => [
                'mailer' => $mailer,
                'last_sent_at' => $sent,
                'last_failed_at' => $failed,
                'tls' => $mail['tls'],
            ],
        ];
    }

    /**
     * Whether the deployed release finished its release steps.
     *
     * Each check here is a step that has been missed on this host before: a
     * route cache older than the release answered 404 after a deploy, and
     * every release since 2026-10-15 has needed `migrate`. A configuration or
     * route cache built before the release was built is stale by definition.
     * Booleans and limits only: never a secret's value or length.
     */
    private function release(): array
    {
        $production = app()->environment('production');
        $stamp = $this->releaseStamp();
        $pending = $this->pendingMigrations();
        $configCachedAt = $this->cachedAt(app()->configurationIsCached(), app()->getCachedConfigPath());
        $routesCachedAt = $this->cachedAt(app()->routesAreCached(), app()->getCachedRoutesPath());
        $debug = (bool) config('app.debug');
        $queueConnection = (string) config('queue.default');
        $maintenanceOpen = strlen(VerifyMaintenanceToken::expectedToken()) >= (int) config('maintenance.min_token_length', 32);
        $cronTokenSet = strlen((string) config('cron.token', '')) >= (int) config('cron.min_token_length', 32);
        $builtAt = $stamp['built_at'] === null ? null : $this->parse($stamp['built_at']);

        $critical = [];
        $warning = [];
        $unknown = [];

        if ($pending === null) {
            $unknown[] = 'migrations_unreadable';
        } elseif ($pending > 0) {
            $critical[] = 'pending_migrations';
        }
        if ($maintenanceOpen) {
            $critical[] = 'maintenance_routes_open';
        }
        if ($builtAt !== null && $configCachedAt !== null && $configCachedAt->lessThan($builtAt)) {
            $critical[] = 'config_cache_stale';
        }
        if ($builtAt !== null && $routesCachedAt !== null && $routesCachedAt->lessThan($builtAt)) {
            $critical[] = 'route_cache_stale';
        }
        if ($production && $debug) {
            $critical[] = 'debug_on';
        }
        if ($production && $queueConnection === 'sync') {
            $critical[] = 'queue_sync';
        }
        if ($production && $configCachedAt === null) {
            $warning[] = 'config_not_cached';
        }
        if ($production && $routesCachedAt === null) {
            $warning[] = 'routes_not_cached';
        }
        if ($production && ! $cronTokenSet) {
            $warning[] = 'cron_token_missing';
        }
        if ($stamp['commit'] === null) {
            $unknown[] = 'no_release_stamp';
        }

        $state = match (true) {
            $critical !== [] => self::CRITICAL,
            $warning !== [] => self::WARNING,
            $unknown !== [] => self::UNKNOWN,
            default => self::OK,
        };

        return [
            'state' => $state,
            'reasons' => [...$critical, ...$warning, ...$unknown],
            'environment' => (string) app()->environment(),
            'commit' => $stamp['commit'],
            'built_at' => $builtAt?->toIso8601String(),
            'pending_migrations' => $pending,
            'config_cached_at' => $configCachedAt?->toIso8601String(),
            'routes_cached_at' => $routesCachedAt?->toIso8601String(),
            'debug' => $debug,
            'queue_connection' => $queueConnection,
            'broadcast_connection' => (string) config('broadcasting.default'),
            'maintenance_routes_open' => $maintenanceOpen,
            'cron_token_set' => $cronTokenSet,
            'php' => [
                'version' => PHP_VERSION,
                'upload_max_filesize' => (string) ini_get('upload_max_filesize'),
                'post_max_size' => (string) ini_get('post_max_size'),
                'memory_limit' => (string) ini_get('memory_limit'),
                'max_execution_time' => (string) ini_get('max_execution_time'),
            ],
        ];
    }

    /**
     * The probes the old System Health card showed, unchanged, so the two
     * cannot disagree about whether the database is up.
     */
    private function services(): array
    {
        $health = $this->system->check();

        $checks = [];
        $unhealthy = false;
        foreach ($health['services'] as $name => $service) {
            $unhealthy = $unhealthy || $service['status'] === 'unhealthy';
            $checks[] = [
                'name' => (string) $name,
                'status' => (string) $service['status'],
                'response_ms' => isset($service['response_ms']) ? (int) $service['response_ms'] : null,
            ];
        }

        return [
            'state' => $unhealthy ? self::CRITICAL : self::OK,
            'reasons' => $unhealthy ? ['service_unhealthy'] : [],
            'checks' => $checks,
            'disk_free_gb' => $health['resources']['disk_free_gb'],
        ];
    }

    /**
     * `release.json` is written by scripts/shared-hosting/build-release.sh and
     * read by scripts/release-status.sh. A development checkout has none.
     *
     * @return array{commit: string|null, built_at: string|null}
     */
    private function releaseStamp(): array
    {
        $path = public_path('release.json');

        if (! is_file($path) || ! is_readable($path)) {
            return ['commit' => null, 'built_at' => null];
        }

        $data = json_decode((string) file_get_contents($path), true);

        if (! is_array($data)) {
            return ['commit' => null, 'built_at' => null];
        }

        $commit = is_string($data['commit'] ?? null) && preg_match('/^[0-9a-f]{7,40}$/', $data['commit']) === 1 ? $data['commit'] : null;
        $builtAt = is_string($data['built_at'] ?? null) ? $data['built_at'] : null;

        return ['commit' => $commit, 'built_at' => $builtAt];
    }

    private function pendingMigrations(): ?int
    {
        try {
            $migrator = app('migrator');

            if (! $migrator->repositoryExists()) {
                return null;
            }

            $files = $migrator->getMigrationFiles(array_merge([database_path('migrations')], $migrator->paths()));

            return count(array_diff(array_keys($files), $migrator->getRepository()->getRan()));
        } catch (Throwable) {
            return null;
        }
    }

    private function cachedAt(bool $cached, string $path): ?Carbon
    {
        if (! $cached) {
            return null;
        }

        $modified = @filemtime($path);

        return $modified === false ? null : Carbon::createFromTimestamp($modified);
    }

    /**
     * @return array{last_run_at: string|null, age_seconds: int|null, state: string}
     */
    private function moment(?Carbon $at, int $warningAfter, int $criticalAfter): array
    {
        $age = $this->age($at);

        return [
            'last_run_at' => $at?->toIso8601String(),
            'age_seconds' => $age,
            'state' => $this->byAge($age, $warningAfter, $criticalAfter, self::UNKNOWN),
        ];
    }

    private function byAge(?int $age, int $warningAfter, int $criticalAfter, string $whenAbsent): string
    {
        return match (true) {
            $age === null => $whenAbsent,
            $age > $criticalAfter => self::CRITICAL,
            $age > $warningAfter => self::WARNING,
            default => self::OK,
        };
    }

    private function age(?Carbon $at): ?int
    {
        return $at === null ? null : (int) $at->diffInSeconds(now(), absolute: true);
    }

    private function parse(string $value): ?Carbon
    {
        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<int, string>  $states
     */
    private function worst(array $states): string
    {
        $worst = self::OK;

        foreach ($states as $state) {
            if (self::RANK[$state] > self::RANK[$worst]) {
                $worst = $state;
            }
        }

        return $worst;
    }
}
