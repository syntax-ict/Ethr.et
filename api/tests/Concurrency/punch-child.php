<?php

declare(strict_types=1);

/**
 * One punch, made by a process of its own (AttendancePunchRaceTest).
 *
 * It boots the application, waits at a barrier until the test releases every
 * child at once, records the punch through AttendanceEngine::record() and
 * prints what came of it as one line of JSON.
 *
 * The database and the cache store come from the job, not from `.env`: a
 * child that read the developer's own settings would punch into the shared
 * database. It refuses to run against anything but the database it was told.
 * The cache store shares that connection, as it does in production, so the
 * engine's lock and its transaction meet the way they do on a real server.
 *
 * Exit codes: 0 recorded (or answered as a duplicate), 1 the engine threw,
 * 3 wrong database, 4 the barrier never opened.
 */

use App\Enums\AttendanceSource;
use App\Models\Tenant;
use App\Services\Attendance\AttendanceEngine;
use App\Services\Attendance\AttendanceInput;
use App\Services\CurrentTenant;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';

date_default_timezone_set('UTC');

/** @var array{id: string, barrier: string, connection: array<string, mixed>, tenant_id: int, employee_id: int, source: string, type: string, key: string, occurred_at: string|null} $job */
$job = json_decode((string) base64_decode($argv[1] ?? '', true), true, 16, JSON_THROW_ON_ERROR);

// Set before the application boots, so every service is built against these
// from the start. Changing the connection afterwards (config() and
// DB::purge()) left the cache store holding the purged connection; its first
// use reconnected by name and closed the live one in the middle of the
// punch's transaction, which then reported success and saved nothing.
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
    fwrite(STDERR, 'Refusing to punch into '.DB::connection()->getDatabaseName().' with the '.config('cache.default').' cache store.'.PHP_EOL);
    exit(3);
}

// RACE_TRACE=1 writes every statement this child ran beside its output, for
// working out why a race came out as it did.
if (getenv('RACE_TRACE') === '1') {
    DB::listen(function ($query) use ($job): void {
        file_put_contents(
            $job['barrier'].'/trace-'.$job['id'],
            sprintf("%.4f %s %s\n", microtime(true), $query->sql, json_encode($query->bindings)),
            FILE_APPEND,
        );
    });
}

// Connect now, so the first query after the barrier is the punch itself.
DB::connection()->getPdo();
$tenant = Tenant::query()->findOrFail($job['tenant_id']);
app(CurrentTenant::class)->set($tenant);

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
    $result = app(AttendanceEngine::class)->record(new AttendanceInput(
        employeeId: $job['employee_id'],
        tenantId: $job['tenant_id'],
        source: AttendanceSource::from($job['source']),
        type: $job['type'],
        idempotencyKey: $job['key'],
        occurredAt: $job['occurred_at'],
    ));

    echo json_encode([
        'record_id' => $result->record->id,
        'was_duplicate' => $result->wasDuplicate,
        'punch' => $result->punch,
        'outcome' => $result->outcome?->code,
        'late_ms' => $lateMs,
    ]);
    exit(0);
} catch (Throwable $e) {
    echo json_encode([
        'error' => $e::class,
        'message' => $e->getMessage(),
        'late_ms' => $lateMs,
    ]);
    exit(1);
}
