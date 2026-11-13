<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Jobs\CleanupExpiredDataJob;
use App\Jobs\ProcessDeviceInboundPunchesJob;
use App\Models\AttendanceRecord;
use App\Models\Branch;
use App\Models\Device;
use App\Models\DeviceInboundPunch;
use App\Models\Employee;
use App\Models\Tenant;
use App\Models\UnmatchedDevicePunch;
use Carbon\Carbon;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;

/*
 * A terminal in push mode is answered at once and its punches are worked off
 * the queue, in arrival order (device_inbound_punches). The terminal sends
 * again anything not answered 200, and the host has five PHP workers, so the
 * request must be one insert and an answer.
 */

afterEach(fn () => Carbon::setTestNow());

/** @return array{0: Tenant, 1: Device, 2: Employee} */
function inboxFixture(): array
{
    Carbon::setTestNow(Carbon::parse('2026-10-14 17:30:00', 'Africa/Addis_Ababa'));

    $tenant = createTenant(['timezone' => 'Africa/Addis_Ababa']);
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);
    $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
    $device = Device::factory()->hikvision()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'connection_config' => ['mode' => 'push'],
        'webhook_token' => 'inbox-token',
        'status' => 'pending',
        'last_sync_at' => null,
    ]);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'badge_number' => '1001']);

    return [$tenant, $device, $employee];
}

/** @param array<string, mixed> $body */
function inboxPost(Tenant $tenant, array $body): TestResponse
{
    return test()->withHeader('X-Webhook-Token', 'inbox-token')
        ->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/devices/webhook/hikvision", $body);
}

/** @return array<string, mixed> */
function inboxEvent(string $badge, string $localTime, string $status = 'checkIn'): array
{
    return [
        'dateTime' => "2026-10-14T{$localTime}+03:00",
        'eventType' => 'AccessControllerEvent',
        'AccessControllerEvent' => [
            'majorEventType' => 5, 'subEventType' => 75,
            'employeeNoString' => $badge,
            'attendanceStatus' => $status,
        ],
    ];
}

function inboxRun(Device $device): void
{
    app()->call([new ProcessDeviceInboundPunchesJob($device->id, $device->tenant_id), 'handle']);
}

it('stores the punch, answers accepted, and leaves the work to the queue', function () {
    Queue::fake();
    [$tenant, $device] = inboxFixture();

    inboxPost($tenant, inboxEvent('1001', '08:02:00'))->assertOk()->assertJsonPath('status', 'accepted');

    $rows = DeviceInboundPunch::withoutGlobalScopes()->get();
    expect($rows)->toHaveCount(1)
        ->and($rows->first()->tenant_id)->toBe($tenant->id)
        ->and($rows->first()->device_id)->toBe($device->id)
        ->and($rows->first()->badge)->toBe('1001')
        ->and($rows->first()->processed_at)->toBeNull()
        ->and(AttendanceRecord::withoutGlobalScopes()->count())->toBe(0)
        ->and(UnmatchedDevicePunch::withoutGlobalScopes()->count())->toBe(0);

    Queue::assertPushed(
        ProcessDeviceInboundPunchesJob::class,
        fn (ProcessDeviceInboundPunchesJob $job) => $job->deviceId === $device->id
            && $job->tenantId === $tenant->id
            && $job->queue === 'attendance',
    );
});

it('keeps one row and queues one job when the terminal sends the same event again', function () {
    Queue::fake();
    [$tenant] = inboxFixture();

    inboxPost($tenant, inboxEvent('1001', '08:02:00'))->assertOk()->assertJsonPath('status', 'accepted');
    inboxPost($tenant, inboxEvent('1001', '08:02:00'))->assertOk()->assertJsonPath('status', 'accepted');

    expect(DeviceInboundPunch::withoutGlobalScopes()->count())->toBe(1);
    Queue::assertPushed(ProcessDeviceInboundPunchesJob::class, 1);
});

it('records a matched badge and holds an unknown one when the queue runs', function () {
    [$tenant, $device, $employee] = inboxFixture();

    inboxPost($tenant, inboxEvent('1001', '08:02:00'))->assertOk();
    inboxPost($tenant, inboxEvent('9999', '08:03:00'))->assertOk();

    expect(AttendanceRecord::withoutGlobalScopes()->where('employee_id', $employee->id)->count())->toBe(1)
        ->and(UnmatchedDevicePunch::withoutGlobalScopes()->where('badge', '9999')->count())->toBe(1)
        ->and(DeviceInboundPunch::withoutGlobalScopes()->whereNull('processed_at')->count())->toBe(0);

    // Run again for the same rows: nothing is recorded twice.
    inboxRun($device);

    expect(AttendanceRecord::withoutGlobalScopes()->count())->toBe(1)
        ->and(UnmatchedDevicePunch::withoutGlobalScopes()->count())->toBe(1);

    // And a matched punch moved the device's last-heard time to its receipt.
    expect($device->refresh()->status)->toBe('online')
        ->and($device->last_sync_at)->not->toBeNull();
});

it('works the rows in arrival order, so the evening scan closes the morning record', function () {
    Queue::fake();
    [$tenant, $device, $employee] = inboxFixture();

    inboxPost($tenant, inboxEvent('1001', '08:00:00', 'undefined'))->assertOk();
    inboxPost($tenant, inboxEvent('1001', '17:00:00', 'undefined'))->assertOk();

    expect(AttendanceRecord::withoutGlobalScopes()->count())->toBe(0);

    inboxRun($device);

    $records = AttendanceRecord::withoutGlobalScopes()->where('employee_id', $employee->id)->get();
    expect($records)->toHaveCount(1)
        ->and($records->first()->check_in)->not->toBeNull()
        ->and($records->first()->check_out)->not->toBeNull();
});

it('leaves a row pending after a database error, and the rows behind it wait', function (string $message) {
    Queue::fake();
    [$tenant, $device, $employee] = inboxFixture();

    inboxPost($tenant, inboxEvent('1001', '08:00:00', 'checkIn'))->assertOk();
    inboxPost($tenant, inboxEvent('9999', '08:05:00', 'checkIn'))->assertOk();

    $fail = true;
    AttendanceRecord::creating(function () use (&$fail, $message): void {
        if ($fail) {
            throw new QueryException('sqlite', 'insert into attendance_records', [], new PDOException($message));
        }
    });

    expect(fn () => inboxRun($device))->toThrow(RuntimeException::class);

    $rows = DeviceInboundPunch::withoutGlobalScopes()->orderBy('id')->get();
    expect($rows[0]->processed_at)->toBeNull()
        ->and($rows[0]->attempts)->toBe(1)
        ->and($rows[1]->processed_at)->toBeNull()
        ->and(UnmatchedDevicePunch::withoutGlobalScopes()->count())->toBe(0);

    // The next run picks up where the failed one stopped.
    $fail = false;
    inboxRun($device);

    expect(DeviceInboundPunch::withoutGlobalScopes()->whereNull('processed_at')->count())->toBe(0)
        ->and(AttendanceRecord::withoutGlobalScopes()->where('employee_id', $employee->id)->count())->toBe(1)
        ->and(UnmatchedDevicePunch::withoutGlobalScopes()->count())->toBe(1);
})->with(['a query error' => 'disk I/O error', 'a deadlock' => 'database is locked']);

it('marks a row processed with the reason when the engine refuses it', function () {
    Queue::fake();
    [$tenant, $device] = inboxFixture();

    // A time in the future of the moment it arrived is refused, not retried.
    inboxPost($tenant, inboxEvent('1001', '23:30:00'))->assertOk();
    inboxRun($device);

    $row = DeviceInboundPunch::withoutGlobalScopes()->sole();
    expect($row->processed_at)->not->toBeNull()
        ->and($row->error)->not->toBeNull()
        ->and(AttendanceRecord::withoutGlobalScopes()->count())->toBe(0);
});

it('does not stop answering 200 under a replay of a hundred and twenty posts a minute', function () {
    Queue::fake();
    [$tenant] = inboxFixture();

    foreach (range(1, 120) as $i) {
        inboxPost($tenant, ['dateTime' => '2026-10-14T09:00:00+03:00', 'eventType' => 'heartBeat'])->assertOk();
    }
});

it('writes the device row for a heartbeat once a minute, not once a post', function () {
    Queue::fake();
    [$tenant, $device] = inboxFixture();
    $beat = ['dateTime' => '2026-10-14T09:00:00+03:00', 'eventType' => 'heartBeat'];
    $start = Carbon::now();

    inboxPost($tenant, $beat)->assertOk()->assertJsonPath('status', 'ignored');
    expect($device->refresh()->last_sync_at->equalTo($start))->toBeTrue();

    Carbon::setTestNow($start->copy()->addSeconds(10));
    inboxPost($tenant, $beat)->assertOk();
    expect($device->refresh()->last_sync_at->equalTo($start))->toBeTrue();

    Carbon::setTestNow($start->copy()->addSeconds(61));
    inboxPost($tenant, $beat)->assertOk();
    expect($device->refresh()->last_sync_at->equalTo($start->copy()->addSeconds(61)))->toBeTrue();
});

it('costs a bounded number of queries to accept a punch', function () {
    Queue::fake();
    [$tenant] = inboxFixture();

    // The first post warms the tenant and feature lookups the test's own
    // setup would otherwise charge to it; the second is the steady state.
    inboxPost($tenant, inboxEvent('1001', '08:00:00'))->assertOk();

    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });
    inboxPost($tenant, inboxEvent('1001', '08:01:00'))->assertOk();

    expect($queries)->toBeLessThanOrEqual(INBOX_QUERY_BUDGET);
});

it('sweeps a pending row older than two minutes and processes it', function () {
    Queue::fake();
    [$tenant, $device, $employee] = inboxFixture();

    inboxPost($tenant, inboxEvent('1001', '08:00:00'))->assertOk();
    Queue::assertPushed(ProcessDeviceInboundPunchesJob::class, 1);

    // Its run never happened (all tries failed, or the queue was cleared):
    // six minutes on (past the job's five-minute unique lock, which a job
    // that never ran would otherwise hold), the schedule queues it again.
    Carbon::setTestNow(Carbon::now()->addMinutes(6));
    expect(ProcessDeviceInboundPunchesJob::sweepStranded())->toBe(1);
    Queue::assertPushed(ProcessDeviceInboundPunchesJob::class, 2);

    inboxRun($device);

    expect(DeviceInboundPunch::withoutGlobalScopes()->whereNull('processed_at')->count())->toBe(0)
        ->and(AttendanceRecord::withoutGlobalScopes()->where('employee_id', $employee->id)->count())->toBe(1);
});

it('leaves a fresh row and a processed one alone when sweeping', function () {
    Queue::fake();
    [$tenant, $device] = inboxFixture();
    $row = fn (string $key, array $over) => DB::table('device_inbound_punches')->insert($over + [
        'tenant_id' => $tenant->id, 'device_id' => $device->id, 'badge' => '1001', 'punch_type' => 'check_in',
        'occurred_raw' => 'x', 'idempotency_key' => $key, 'processed_at' => null,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $row('fresh', ['created_at' => now()->subSeconds(90)]);
    $row('done', ['created_at' => now()->subMinutes(10), 'processed_at' => now()->subMinutes(9)]);

    expect(ProcessDeviceInboundPunchesJob::sweepStranded())->toBe(0);
    Queue::assertNothingPushed();
});

it('runs the sweep from the schedule every five minutes', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event) => $event->description === 'device-inbox-sweep');

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('*/5 * * * *')
        ->and($event->withoutOverlapping)->toBeTrue();
});

it('deletes processed inbox rows after a week and never a pending one', function () {
    [$tenant, $device] = inboxFixture();
    $row = fn (array $over) => DB::table('device_inbound_punches')->insertGetId($over + [
        'tenant_id' => $tenant->id, 'device_id' => $device->id, 'badge' => '1', 'punch_type' => 'auto',
        'occurred_raw' => 'x', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $old = $row(['idempotency_key' => 'a', 'processed_at' => now()->subDays(8)]);
    $recent = $row(['idempotency_key' => 'b', 'processed_at' => now()->subDays(2)]);
    $pending = $row(['idempotency_key' => 'c', 'processed_at' => null, 'created_at' => now()->subDays(30)]);

    (new CleanupExpiredDataJob)->handle();

    expect(DB::table('device_inbound_punches')->pluck('id')->all())->toBe([$recent, $pending]);
    expect($old)->not->toBe($recent);
});

// Measured 3 (device by token, tenant, the insert); the slack is for one more
// lookup, not for a per-post engine run.
const INBOX_QUERY_BUDGET = 6;
