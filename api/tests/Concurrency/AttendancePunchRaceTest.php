<?php

declare(strict_types=1);

use App\Enums\AttendanceSource;
use App\Enums\AttendanceStatus;
use App\Models\AttendanceConflict;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSetting;
use App\Models\Employee;
use App\Models\Tenant;
use App\Services\Attendance\AttendanceEngine;
use App\Services\Attendance\AttendanceInput;
use App\Services\CurrentTenant;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Punches that reach the server in the same instant (acceptance matrix A6).
 *
 * Each case starts one PHP process per punch (punch-child.php), holds them
 * all at a barrier until every one has booted and connected, and releases
 * them together. What is asserted is what the database holds afterwards.
 *
 * Run with `./scripts/gates.sh concurrency`; phpunit.concurrency.xml says why
 * this cannot be a Feature test.
 */
const RACE_PEOPLE = 12;

beforeEach(function () {
    $database = DB::connection()->getDatabaseName();

    // Fixtures are committed here, never rolled back. A name that does not
    // say it is a scratch database is somebody's data.
    if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)
        || preg_match('/concurrency|scratch|suite/', $database) !== 1
        || config('cache.default') !== 'database') {
        throw new RuntimeException(
            "The concurrency suite needs MySQL/MariaDB, a scratch database and the database cache store; got [{$database}]. Run it with ./scripts/gates.sh concurrency."
        );
    }

    static $migrated = false;
    if (! $migrated) {
        Artisan::call('migrate', ['--force' => true]);
        $migrated = true;
    }
});

/**
 * A tenant of this run's own, with its attendance rules and its people.
 *
 * @return array{0: Tenant, 1: Collection<int, Employee>}
 */
function raceFixture(int $people = RACE_PEOPLE): array
{
    $tenant = Tenant::factory()->create(['subdomain' => 'race-'.Str::lower(Str::random(12))]);
    AttendanceSetting::create([...AttendanceSetting::defaults(), 'tenant_id' => $tenant->id]);
    app(CurrentTenant::class)->set($tenant);

    $employees = collect(range(1, $people))->map(fn (int $i) => Employee::factory()->create([
        'tenant_id' => $tenant->id,
        'email' => "race-{$tenant->id}-{$i}@example.test",
        'employee_code' => sprintf('RACE-%04d', $i),
    ]));

    return [$tenant, $employees];
}

/**
 * Run every job in a process of its own, all released at one instant.
 *
 * @param  list<array{employee_id: int, source: AttendanceSource, type: string, key: string, occurred_at?: string|null}>  $jobs
 * @return list<array{exit: int, employee_id: int, source: string, out: array<string, mixed>, err: string}>
 */
function race(Tenant $tenant, array $jobs): array
{
    $barrier = sys_get_temp_dir().DIRECTORY_SEPARATOR.'ethr-race-'.Str::lower(Str::random(12));
    mkdir($barrier, 0777, true);

    $connection = array_intersect_key(
        config('database.connections.'.config('database.default')),
        array_flip(['host', 'port', 'database', 'username', 'password']),
    );

    $children = [];
    foreach ($jobs as $i => $job) {
        $payload = base64_encode(json_encode([
            'id' => (string) $i,
            'barrier' => $barrier,
            'connection' => $connection,
            'tenant_id' => $tenant->id,
            'employee_id' => $job['employee_id'],
            'source' => $job['source']->value,
            'type' => $job['type'],
            'key' => $job['key'],
            'occurred_at' => $job['occurred_at'] ?? null,
        ], JSON_THROW_ON_ERROR));

        $process = proc_open(
            [PHP_BINARY, '-d', 'memory_limit=-1', __DIR__.DIRECTORY_SEPARATOR.'punch-child.php', $payload],
            [1 => ['file', "{$barrier}/out-{$i}", 'w'], 2 => ['file', "{$barrier}/err-{$i}", 'w']],
            $pipes,
            dirname(__DIR__, 2),
        );
        if ($process === false) {
            throw new RuntimeException('Could not start a punch process.');
        }
        $children[$i] = ['process' => $process, 'job' => $job, 'exit' => null];
    }

    // Every child booted and connected, or one died trying.
    $deadline = microtime(true) + 180;
    while (count(glob("{$barrier}/ready-*") ?: []) < count($children)) {
        foreach ($children as $i => $child) {
            $status = proc_get_status($child['process']);
            if (! $status['running']) {
                throw new RuntimeException('A punch process stopped before the barrier: '.file_get_contents("{$barrier}/err-{$i}").file_get_contents("{$barrier}/out-{$i}"));
            }
        }
        if (microtime(true) > $deadline) {
            throw new RuntimeException('The punch processes did not all start within three minutes.');
        }
        usleep(20000);
    }

    // Written whole, then renamed: a child must never read half a number.
    file_put_contents("{$barrier}/go.tmp", sprintf('%.6F', microtime(true) + 0.5));
    rename("{$barrier}/go.tmp", "{$barrier}/go");

    $results = [];
    $deadline = microtime(true) + 120;
    foreach ($children as $i => $child) {
        while (true) {
            $status = proc_get_status($child['process']);
            if (! $status['running']) {
                // proc_get_status reports the real exit code once only.
                $exit = $status['exitcode'];
                break;
            }
            if (microtime(true) > $deadline) {
                proc_terminate($child['process']);
                throw new RuntimeException('A punch process did not finish within two minutes.');
            }
            usleep(10000);
        }
        proc_close($child['process']);

        $results[] = [
            'exit' => $exit,
            'employee_id' => $child['job']['employee_id'],
            'source' => $child['job']['source']->value,
            'out' => json_decode((string) file_get_contents("{$barrier}/out-{$i}"), true) ?? [],
            'err' => trim((string) file_get_contents("{$barrier}/err-{$i}")),
        ];
    }

    // RACE_TRACE=1: the statements each child ran, in the order it ran them.
    if (getenv('RACE_TRACE') === '1') {
        foreach (glob("{$barrier}/trace-*") ?: [] as $trace) {
            fwrite(STDERR, PHP_EOL.basename($trace).PHP_EOL.file_get_contents($trace));
        }
    }

    array_map('unlink', glob("{$barrier}/*") ?: []);
    rmdir($barrier);

    return $results;
}

/** What went wrong in the children, for the failure message. */
function raceFailures(array $results): array
{
    return array_values(array_filter(array_map(
        fn (array $r) => $r['exit'] === 0 ? null : "{$r['source']} for {$r['employee_id']}: exit {$r['exit']} ".json_encode($r['out']).' '.$r['err'],
        $results,
    )));
}

it('keeps one live record when two methods check a person in at the same instant', function () {
    [$tenant, $employees] = raceFixture();

    $jobs = [];
    foreach ($employees as $employee) {
        $jobs[] = ['employee_id' => $employee->id, 'source' => AttendanceSource::MOBILE, 'type' => 'check_in', 'key' => "race-in-mobile-{$employee->id}"];
        $jobs[] = ['employee_id' => $employee->id, 'source' => AttendanceSource::KIOSK, 'type' => 'check_in', 'key' => "race-in-kiosk-{$employee->id}"];
    }

    $results = race($tenant, $jobs);
    expect(raceFailures($results))->toBe([]);

    foreach ($employees as $employee) {
        $rows = AttendanceRecord::query()->where('employee_id', $employee->id)->get();
        $live = $rows->filter(fn (AttendanceRecord $r) => $r->status !== AttendanceStatus::VOIDED)->values();
        $voided = $rows->filter(fn (AttendanceRecord $r) => $r->status === AttendanceStatus::VOIDED)->values();

        expect($live)->toHaveCount(1, "employee {$employee->id} has {$live->count()} live records");
        expect($voided->count())->toBeLessThanOrEqual(1);
        foreach ($voided as $loser) {
            expect($loser->metadata['merged_into'] ?? null)->toBe($live[0]->public_id);
        }

        // Both methods' punches are on record. They are read across the live
        // row and its voided twin: when the later punch wins the merge, the
        // first one's log entry stays on the row that was voided.
        $sources = $rows->flatMap(fn (AttendanceRecord $r) => collect($r->metadata['punches'] ?? [])->pluck('source'))->unique()->sort()->values()->all();
        expect($sources)->toBe(['kiosk', 'mobile']);
    }

    expect(AttendanceConflict::query()->where('tenant_id', $tenant->id)->count())->toBe(0);
});

it('answers a retry that races its original with the one record', function () {
    [$tenant, $employees] = raceFixture();

    $jobs = [];
    foreach ($employees as $employee) {
        foreach ([1, 2] as $attempt) {
            $jobs[] = ['employee_id' => $employee->id, 'source' => AttendanceSource::WEB, 'type' => 'check_in', 'key' => "race-retry-{$employee->id}"];
        }
    }

    $results = race($tenant, $jobs);
    expect(raceFailures($results))->toBe([]);

    foreach ($employees as $employee) {
        expect(AttendanceRecord::query()->where('employee_id', $employee->id)->count())->toBe(1);

        $answers = collect($results)->where('employee_id', $employee->id);
        expect($answers->pluck('out.record_id')->unique()->count())->toBe(1)
            ->and($answers->where('out.was_duplicate', true)->count())->toBe(1);
    }
});

it('closes the day once when two methods check a person out at the same instant', function () {
    [$tenant, $employees] = raceFixture();
    $engine = app(AttendanceEngine::class);

    foreach ($employees as $employee) {
        $engine->record(new AttendanceInput(
            employeeId: $employee->id,
            tenantId: $tenant->id,
            source: AttendanceSource::WEB,
            type: 'check_in',
            idempotencyKey: "race-open-{$employee->id}",
            occurredAt: now('UTC')->subHours(4)->toIso8601String(),
        ));
    }

    $jobs = [];
    foreach ($employees as $employee) {
        $jobs[] = ['employee_id' => $employee->id, 'source' => AttendanceSource::MOBILE, 'type' => 'check_out', 'key' => "race-out-mobile-{$employee->id}"];
        $jobs[] = ['employee_id' => $employee->id, 'source' => AttendanceSource::BIOMETRIC, 'type' => 'check_out', 'key' => "race-out-device-{$employee->id}"];
    }

    $results = race($tenant, $jobs);
    expect(raceFailures($results))->toBe([]);

    foreach ($employees as $employee) {
        $record = AttendanceRecord::query()->where('employee_id', $employee->id)->sole();
        $departures = collect($record->metadata['punches'])->where('kind', 'check_out');

        expect($record->check_out)->not->toBeNull()
            ->and($departures->pluck('outcome')->sort()->values()->all())->toBe(['applied', 'duplicate'])
            ->and($departures->pluck('source')->sort()->values()->all())->toBe(['biometric', 'mobile']);
    }
});

// The lock that serialises a person's punches gives up after eight seconds
// and lets the punch through: a delayed punch is worse than a flagged one.
// This pins that it is recorded, not lost and not refused.
it('records the punch when the lock cannot be had in time', function () {
    [$tenant, $employees] = raceFixture(1);
    $employee = $employees[0];

    $held = Cache::lock("attendance-punch:{$tenant->id}:{$employee->id}", 60);
    expect($held->get())->toBeTrue();

    try {
        $started = microtime(true);
        $result = app(AttendanceEngine::class)->record(new AttendanceInput(
            employeeId: $employee->id,
            tenantId: $tenant->id,
            source: AttendanceSource::KIOSK,
            type: 'check_in',
            idempotencyKey: "race-locked-{$employee->id}",
        ));
        $waited = microtime(true) - $started;
    } finally {
        $held->release();
    }

    expect($waited)->toBeGreaterThan(7.0)
        ->and($result->wasDuplicate)->toBeFalse()
        ->and(AttendanceRecord::query()->where('employee_id', $employee->id)->count())->toBe(1);
});
