<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Services\Admin\OperationsStatusService;
use App\Services\Observability\QueueHealth;
use App\Support\Mail\MailHealth;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The operations centre, phase 1 (docs/operations/OPERATIONS-CENTRE-SPEC.md).
 *
 * Each area is driven into every state it can report, because a monitor
 * nobody has watched fail is an assumption with a dashboard. Two cases carry
 * the most weight: `unknown` must never read as an alarm (a fresh install has
 * no heartbeat and is not broken), and a dead external caller must show while
 * page traffic keeps the scheduler heartbeat fresh — the blind spot this
 * module exists to close.
 */
const OPS_CRON_TOKEN = 'a-token-that-is-at-least-thirty-two-chars-long';

beforeEach(function () {
    Cache::flush();
});

afterEach(function () {
    app()->detectEnvironment(fn () => 'testing');
});

function operationsAsSuperAdmin(): array
{
    $tenant = createTenant();
    $user = createUser(['role' => UserRole::SUPER_ADMIN, 'mfa_enabled' => true], $tenant);
    test()->actingAs($user);

    return test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/admin/operations")
        ->assertOk()
        ->json();
}

function insertJob(string $queue, int $waitedSeconds): void
{
    DB::table('jobs')->insert([
        'queue' => $queue,
        'payload' => '{}',
        'attempts' => 0,
        'reserved_at' => null,
        'available_at' => time() - $waitedSeconds,
        'created_at' => time() - $waitedSeconds,
    ]);
}

test('a super admin sees every area with a state', function () {
    $status = operationsAsSuperAdmin();

    expect($status)->toHaveKeys(['status', 'checked_at', 'background', 'failed_jobs', 'delivery', 'release', 'services']);

    foreach (['background', 'failed_jobs', 'delivery', 'release', 'services'] as $area) {
        expect($status[$area]['state'])->toBeIn(['ok', 'warning', 'critical', 'unknown'])
            ->and($status[$area]['reasons'])->toBeArray();
    }

    expect(array_column($status['background']['queues'], 'name'))->toBe(QueueHealth::QUEUES);
});

test('a tenant admin cannot read it', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/admin/operations")->assertForbidden();
});

test('a fresh install reads unknown, never as an alarm', function () {
    $status = operationsAsSuperAdmin();

    expect($status['background']['state'])->toBe('unknown')
        ->and($status['background']['reasons'])->toContain('scheduler_never_run', 'external_caller_never_seen')
        ->and($status['background']['scheduler']['state'])->toBe('unknown')
        ->and($status['status'])->not->toBeIn(['warning', 'critical']);
});

test('a dead external caller shows while page traffic keeps the heartbeat fresh', function () {
    $health = app(QueueHealth::class);

    $health->recordExternalTick();
    $this->travel(OperationsStatusService::EXTERNAL_WARNING_SECONDS + 60)->seconds();
    $health->beat();
    $health->recordTrafficTick();

    $status = operationsAsSuperAdmin();

    expect($status['background']['scheduler']['state'])->toBe('ok')
        ->and($status['background']['page_traffic']['last_run_at'])->not->toBeNull()
        ->and($status['background']['external_caller']['state'])->toBe('warning')
        ->and($status['background']['state'])->toBe('warning')
        ->and($status['background']['reasons'])->toContain('external_caller_stale');

    $this->travel(OperationsStatusService::EXTERNAL_CRITICAL_SECONDS)->seconds();
    $health->beat();

    expect(operationsAsSuperAdmin()['background']['external_caller']['state'])->toBe('critical');
});

test('a scheduler that has stopped is critical', function () {
    app(QueueHealth::class)->beat();
    app(QueueHealth::class)->recordExternalTick();

    $this->travel(OperationsStatusService::SCHEDULER_CRITICAL_SECONDS + 60)->seconds();

    $status = operationsAsSuperAdmin();

    expect($status['background']['scheduler']['state'])->toBe('critical')
        ->and($status['background']['reasons'])->toContain('scheduler_stale')
        ->and($status['status'])->toBe('critical');
});

test('a job waiting too long turns its queue red, and only its queue', function () {
    insertJob('exports', OperationsStatusService::QUEUE_CRITICAL_SECONDS + 60);
    insertJob('default', 10);

    $queues = collect(operationsAsSuperAdmin()['background']['queues'])->keyBy('name');

    expect($queues['exports']['state'])->toBe('critical')
        ->and($queues['exports']['depth'])->toBe(1)
        ->and($queues['default']['state'])->toBe('ok')
        ->and($queues['attendance']['oldest_waiting_seconds'])->toBeNull();
});

test('failed jobs are worth a look but never an alarm', function () {
    DB::table('failed_jobs')->insert([
        'uuid' => (string) Str::uuid(),
        'connection' => 'database',
        'queue' => 'default',
        'payload' => '{}',
        'exception' => 'RuntimeException: boom',
        'failed_at' => now(),
    ]);

    $failed = operationsAsSuperAdmin()['failed_jobs'];

    expect($failed['state'])->toBe('warning')
        ->and($failed['count'])->toBe(1)
        ->and($failed['last_failed_at'])->not->toBeNull();
});

test('mail is red only while the last failure is later than the last send', function () {
    config()->set('mail.default', 'smtp');

    MailHealth::recordSent('verified');
    $this->travel(5)->minutes();
    MailHealth::recordFailure();

    expect(operationsAsSuperAdmin()['delivery']['state'])->toBe('critical');

    $this->travel(5)->minutes();
    MailHealth::recordSent('verified');

    $delivery = operationsAsSuperAdmin()['delivery'];
    expect($delivery['state'])->toBe('ok')
        ->and($delivery['mail']['tls'])->toBe('verified');
});

test('a mailer that sends nothing is unknown, not healthy', function () {
    config()->set('mail.default', 'log');
    MailHealth::recordSent('verified');

    $delivery = operationsAsSuperAdmin()['delivery'];

    expect($delivery['state'])->toBe('unknown')
        ->and($delivery['reasons'])->toBe(['mail_not_sent_by_this_mailer']);
});

test('open maintenance routes are critical, and the token is never shown', function () {
    $token = str_repeat('m', 40);
    config()->set('maintenance.token', $token);
    config()->set('maintenance.read_env_file', false);
    config()->set('cron.token', OPS_CRON_TOKEN);

    $tenant = createTenant();
    test()->actingAs(createUser(['role' => UserRole::SUPER_ADMIN, 'mfa_enabled' => true], $tenant));
    $response = test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/admin/operations")->assertOk();

    expect($response->json('release.state'))->toBe('critical')
        ->and($response->json('release.reasons'))->toContain('maintenance_routes_open')
        ->and($response->json('release.cron_token_set'))->toBeTrue()
        ->and($response->getContent())->not->toContain($token)
        ->and($response->getContent())->not->toContain(OPS_CRON_TOKEN);
});

test('production with debug on is critical', function () {
    app()->detectEnvironment(fn () => 'production');
    config()->set('app.debug', true);

    $release = operationsAsSuperAdmin()['release'];

    expect($release['environment'])->toBe('production')
        ->and($release['state'])->toBe('critical')
        ->and($release['reasons'])->toContain('debug_on', 'config_not_cached', 'routes_not_cached');
});

test('a route cache older than the release is stale', function () {
    // Under the app root and named relatively: Laravel treats a cache path that
    // does not start with a slash as relative to it, and `C:\…` does not.
    $relative = 'storage/framework/testing/ops-'.bin2hex(random_bytes(4));
    $dir = base_path($relative);
    mkdir($dir, 0777, true);
    $publicPath = public_path();
    $routes = $dir.'/routes.php';
    file_put_contents($routes, '<?php');
    touch($routes, time() - 3600);
    file_put_contents($dir.'/release.json', json_encode(['commit' => 'abc1234', 'built_at' => now()->toIso8601String()]));

    $_SERVER['APP_ROUTES_CACHE'] = $relative.'/routes.php';
    // Laravel memoises "were the routes loaded from the cache?" at boot.
    app()->instance('routes.cached', true);
    app()->usePublicPath($dir);

    try {
        $release = operationsAsSuperAdmin()['release'];
    } finally {
        unset($_SERVER['APP_ROUTES_CACHE']);
        app()->usePublicPath($publicPath);
        @unlink($routes);
        @unlink($dir.'/release.json');
        @rmdir($dir);
    }

    expect($release['commit'])->toBe('abc1234')
        ->and($release['routes_cached_at'])->not->toBeNull()
        ->and($release['state'])->toBe('critical')
        ->and($release['reasons'])->toContain('route_cache_stale');
});

test('a test database has no pending migrations', function () {
    expect(operationsAsSuperAdmin()['release']['pending_migrations'])->toBe(0);
});

test('the external caller is recorded on arrival, even when a run is already going', function () {
    config()->set('cron.token', OPS_CRON_TOKEN);
    config()->set('cron.min_token_length', 32);
    Cache::lock('cron:schedule', 110)->get();

    $this->withHeader('X-Cron-Token', OPS_CRON_TOKEN)
        ->postJson('/api/v1/cron/schedule')
        ->assertStatus(409);

    expect(app(QueueHealth::class)->lastExternalTick())->not->toBeNull()
        ->and(app(QueueHealth::class)->lastTrafficTick())->toBeNull();
});

test('a rejected token records nothing', function () {
    config()->set('cron.token', OPS_CRON_TOKEN);

    $this->withHeader('X-Cron-Token', 'wrong-but-also-thirty-two-characters-long')
        ->postJson('/api/v1/cron/queue')
        ->assertNotFound();

    expect(app(QueueHealth::class)->lastExternalTick())->toBeNull();
});

test('page traffic is recorded as traffic, not as the external caller', function () {
    config()->set('cron.opportunistic', true);
    config()->set('cron.opportunistic_requires_early_flush', false);
    Artisan::shouldReceive('call')->andReturn(0);
    Artisan::shouldReceive('output')->andReturn('');

    $this->getJson('/api/v1/health')->assertSuccessful();

    expect(app(QueueHealth::class)->lastTrafficTick())->not->toBeNull()
        ->and(app(QueueHealth::class)->lastExternalTick())->toBeNull();
});

test('a failed health probe names the probe, not the driver error', function () {
    config()->set('filesystems.default', 'no-such-disk-for-this-test');

    $tenant = createTenant();
    test()->actingAs(createUser(['role' => UserRole::SUPER_ADMIN, 'mfa_enabled' => true], $tenant));

    $response = test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/admin/health")
        ->assertOk()
        ->assertJsonPath('services.storage.status', 'unhealthy')
        ->assertJsonPath('services.storage.error', 'unavailable');

    expect($response->getContent())->not->toContain('no-such-disk-for-this-test');
});
