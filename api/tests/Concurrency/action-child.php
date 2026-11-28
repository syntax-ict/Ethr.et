<?php

declare(strict_types=1);

/**
 * One action, made by a process of its own (IdempotencyRaceTest).
 *
 * The same shape as punch-child.php: it boots the application against the
 * database it was told, waits at a barrier until the test releases every child
 * at once, does one thing and prints what came of it as one line of JSON.
 *
 * Actions:
 *   http         a request through the HTTP kernel, signed in as `user_id`
 *                (the controller, its middleware and its locks are in the race)
 *   process_job  ProcessPayrollJob::handle() for a reserved run
 *   accrue       LeaveBalanceService::accrueMonthly() for a tenant
 *
 * Exit codes: 0 done, 1 the action threw, 3 wrong database, 4 the barrier never
 * opened.
 */

use App\Jobs\ProcessPayrollJob;
use App\Models\Tenant;
use App\Models\User;
use App\Services\CurrentTenant;
use App\Services\Leave\LeaveBalanceService;
use App\Services\Payroll\PayrollEngine;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

require __DIR__.'/../../vendor/autoload.php';

date_default_timezone_set('UTC');

/** @var array<string, mixed> $job */
$job = json_decode((string) base64_decode($argv[1] ?? '', true), true, 16, JSON_THROW_ON_ERROR);

// Set before the application boots, as punch-child.php does and for the same
// reason: a connection changed after boot leaves the cache store holding a
// purged one.
$environment = [
    'APP_ENV' => 'testing',
    'DB_CONNECTION' => 'mysql',
    'DB_URL' => '',
    'DB_HOST' => (string) $job['connection']['host'],
    'DB_PORT' => (string) $job['connection']['port'],
    'DB_DATABASE' => (string) $job['connection']['database'],
    'DB_USERNAME' => (string) $job['connection']['username'],
    'DB_PASSWORD' => (string) $job['connection']['password'],
    'CACHE_STORE' => 'database',
    'QUEUE_CONNECTION' => 'sync',
    'MAIL_MAILER' => 'array',
    'BROADCAST_CONNECTION' => 'null',
    'SESSION_DRIVER' => 'array',
];
foreach ($environment as $name => $value) {
    putenv("{$name}={$value}");
    $_ENV[$name] = $value;
    $_SERVER[$name] = $value;
}

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (DB::connection()->getDatabaseName() !== $job['connection']['database'] || config('cache.default') !== 'database') {
    fwrite(STDERR, 'Refusing to act on '.DB::connection()->getDatabaseName().' with the '.config('cache.default').' cache store.'.PHP_EOL);
    exit(3);
}

// Connect now, so the first query after the barrier is the action itself.
DB::connection()->getPdo();

// An http action warms the request pipeline first with a read of its own, so
// the class loading and service construction of a cold process are done before
// the barrier. Cold, a request took a second under contention and the children
// reached the critical section far apart; warm, they reach it together.
if ($job['action'] === 'http' && ! empty($job['warm_url'])) {
    Sanctum::actingAs(User::query()->withoutGlobalScopes()->findOrFail($job['user_id']), ['*']);
    $app->make(HttpKernel::class)->handle(Request::create(
        (string) $job['warm_url'],
        'GET',
        [],
        [],
        [],
        ['HTTP_ACCEPT' => 'application/json'],
    ));
}

file_put_contents($job['barrier'].'/ready-'.$job['id'], '1');

$waited = microtime(true);
while (! is_file($job['barrier'].'/go')) {
    if (microtime(true) - $waited > 120) {
        fwrite(STDERR, 'The barrier never opened.'.PHP_EOL);
        exit(4);
    }
    usleep(2000);
}

$start = (float) file_get_contents($job['barrier'].'/go');
$lateMs = (int) round(max(0, microtime(true) - $start) * 1000);
if (microtime(true) < $start) {
    time_sleep_until($start);
}

try {
    switch ($job['action']) {
        case 'http':
            $user = User::query()->withoutGlobalScopes()->findOrFail($job['user_id']);
            Sanctum::actingAs($user, ['*']);
            $request = Request::create(
                (string) $job['url'],
                (string) $job['method'],
                [],
                [],
                [],
                ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
                json_encode($job['body'] ?? new stdClass, JSON_THROW_ON_ERROR),
            );
            $began = microtime(true);
            $response = $app->make(HttpKernel::class)->handle($request);
            echo json_encode([
                'status' => $response->getStatusCode(),
                'body' => json_decode((string) $response->getContent(), true),
                'late_ms' => $lateMs,
                'took_ms' => (int) round((microtime(true) - $began) * 1000),
            ]);
            exit(0);

        case 'process_job':
            (new ProcessPayrollJob((int) $job['run_id'], (int) $job['tenant_id']))->handle(app(PayrollEngine::class));
            echo json_encode(['done' => true, 'late_ms' => $lateMs]);
            exit(0);

        case 'accrue':
            app(CurrentTenant::class)->set(Tenant::query()->findOrFail((int) $job['tenant_id']));
            $accrued = app(LeaveBalanceService::class)->accrueMonthly((int) $job['tenant_id']);
            echo json_encode(['accrued' => $accrued, 'late_ms' => $lateMs]);
            exit(0);

        default:
            fwrite(STDERR, 'Unknown action '.$job['action'].PHP_EOL);
            exit(2);
    }
} catch (Throwable $e) {
    echo json_encode([
        'error' => $e::class,
        'message' => $e->getMessage(),
        'late_ms' => $lateMs,
    ]);
    exit(1);
}
