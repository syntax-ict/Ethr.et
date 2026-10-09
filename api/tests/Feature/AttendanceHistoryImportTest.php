<?php

declare(strict_types=1);

use App\Contracts\DeviceAdapter;
use App\Enums\UserRole;
use App\Jobs\PullDeviceEventsJob;
use App\Models\AttendanceRecord;
use App\Models\Branch;
use App\Models\Device;
use App\Models\DeviceSyncLog;
use App\Models\Employee;
use App\Models\Shift;
use App\Models\Tenant;
use App\Services\Attendance\AttendanceEngine;
use App\Services\CurrentTenant;
use App\Services\Device\DeviceManager;
use App\Services\Device\MockAdapter;
use App\Services\Identity\IdentityResolver;
use Carbon\Carbon;
use Illuminate\Support\Facades\Queue;

/**
 * A device whose API caps each response at a page size, so draining a long
 * history requires several since-advanced calls — exactly the shape the job's
 * backfill loop must handle.
 */
class PaginatingFakeAdapter implements DeviceAdapter
{
    /** @var array<int, array{employee_badge: string, timestamp: string, type: string}> */
    public array $events = [];

    public int $pageSize = 100;

    /**
     * Device APIs disagree on whether `since` is inclusive. Hikvision's
     * `startTime` returns events AT the start; a strictly-after filter does
     * not. The job has to lose nothing under either.
     */
    public bool $inclusive = false;

    /** Seconds the clock moves per call, to make the job's time budget reachable. */
    public int $secondsPerPull = 0;

    public int $pulls = 0;

    public function connect(Device $device): bool
    {
        return true;
    }

    public function getStatus(Device $device): array
    {
        return ['online' => true];
    }

    public function getDeviceInfo(Device $device): array
    {
        return [];
    }

    public function pullEvents(Device $device, ?string $since = null): array
    {
        $this->pulls++;
        if ($this->secondsPerPull > 0) {
            Carbon::setTestNow(Carbon::now()->addSeconds($this->secondsPerPull));
        }

        $after = $since ? strtotime($since) : PHP_INT_MIN;

        $matching = array_values(array_filter(
            $this->events,
            fn (array $e): bool => $this->inclusive
                ? strtotime($e['timestamp']) >= $after
                : strtotime($e['timestamp']) > $after,
        ));
        usort($matching, fn (array $a, array $b): int => strtotime($a['timestamp']) <=> strtotime($b['timestamp']));

        return array_slice($matching, 0, $this->pageSize);
    }

    public function pullEnrollments(Device $device): array
    {
        return [];
    }

    public function pushEventUrl(Device $device, string $callbackUrl): bool
    {
        return false;
    }
}

afterEach(function () {
    Carbon::setTestNow();
});

function runPull(Device $device, string $triggeredBy = 'manual', ?string $since = null): void
{
    (new PullDeviceEventsJob($device, $triggeredBy, $since))->handle(
        app(DeviceManager::class),
        app(AttendanceEngine::class),
        app(IdentityResolver::class),
    );
}

/**
 * A tenant with one employee per badge and a device routed to a fresh
 * paginating fake.
 *
 * @param  list<string>  $badges
 * @return array{0: Tenant, 1: Device, 2: PaginatingFakeAdapter}
 */
function pagedDevice(array $badges): array
{
    $tenant = createTenant();
    $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
    foreach ($badges as $badge) {
        Employee::factory()->create(['tenant_id' => $tenant->id, 'employee_code' => "EMP-{$badge}", 'badge_number' => $badge]);
    }

    $fake = new PaginatingFakeAdapter;
    app()->instance(MockAdapter::class, $fake);

    return [$tenant, mockDevice($tenant->id, $branch->id), $fake];
}

/**
 * One 08:00 check-in a day, oldest first, ending yesterday-ish. A day apart so
 * that no two punches are ever a conflict for the attendance engine.
 *
 * @return list<array{employee_badge: string, timestamp: string, type: string}>
 */
function dailyCheckIns(string $badge, int $count): array
{
    $events = [];
    for ($i = 0; $i < $count; $i++) {
        $events[] = [
            'employee_badge' => $badge,
            'timestamp' => Carbon::now()->subDays($count + 5 - $i)->setTime(8, 0)->format('Y-m-d\TH:i:sP'),
            'type' => 'check_in',
        ];
    }

    return $events;
}

function secondAfter(string $timestamp, int $seconds): string
{
    return Carbon::parse($timestamp)->addSeconds($seconds)->format('Y-m-d\TH:i:sP');
}

function mockDevice(int $tenantId, int $branchId): Device
{
    return Device::factory()->create([
        'tenant_id' => $tenantId,
        'branch_id' => $branchId,
        'adapter_type' => 'mock',
        'status' => 'online',
        'last_sync_at' => null,
    ]);
}

describe('attendance history import', function () {
    it('dates backfilled punches in the requested window, not today', function () {
        Carbon::setTestNow('2026-07-31 09:00:00');

        $tenant = createTenant();
        $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
        $employee = Employee::factory()->create([
            'tenant_id' => $tenant->id,
            'employee_code' => 'EMP-1',
            'badge_number' => '1001',
        ]);
        $device = mockDevice($tenant->id, $branch->id);

        $since = Carbon::now()->subDays(45)->format('Y-m-d\TH:i:sP');
        runPull($device, 'history_import', $since);

        $record = AttendanceRecord::where('tenant_id', $tenant->id)
            ->where('employee_id', $employee->id)
            ->first();

        expect($record)->not->toBeNull();
        // Mock events start at `since` (+ minutes), so the punch lands 45 days ago.
        expect($record->date->format('Y-m-d'))->toBe('2026-06-16');
    });

    it('leaves the incremental cursor untouched during a history import', function () {
        Carbon::setTestNow('2026-07-31 09:00:00');

        $tenant = createTenant();
        $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
        Employee::factory()->create(['tenant_id' => $tenant->id, 'employee_code' => 'EMP-1', 'badge_number' => '1001']);
        $device = mockDevice($tenant->id, $branch->id);

        runPull($device, 'history_import', Carbon::now()->subDays(30)->format('Y-m-d\TH:i:sP'));

        expect($device->fresh()->last_sync_at)->toBeNull();
    });

    it('still advances the cursor on a normal incremental sync', function () {
        Carbon::setTestNow('2026-07-31 09:00:00');

        $tenant = createTenant();
        $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
        Employee::factory()->create(['tenant_id' => $tenant->id, 'employee_code' => 'EMP-1', 'badge_number' => '1001']);
        $device = mockDevice($tenant->id, $branch->id);

        runPull($device, 'manual');

        expect($device->fresh()->last_sync_at)->not->toBeNull();
    });
});

describe('history import endpoint', function () {
    it('accepts a preset window and backfills attendance', function () {
        Carbon::setTestNow('2026-07-31 09:00:00');

        $tenant = createTenant();
        actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);
        $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
        Employee::factory()->create(['tenant_id' => $tenant->id, 'employee_code' => 'EMP-1', 'badge_number' => '1001']);
        $device = mockDevice($tenant->id, $branch->id);

        test()->postJson(
            "http://{$tenant->subdomain}.ethr.test/api/v1/devices/{$device->public_id}/import-history",
            ['window' => 'last_30']
        )
            ->assertStatus(202)
            ->assertJsonPath('window', 'last_30')
            ->assertJsonPath('device_public_id', $device->public_id);

        // Sync queue runs the job inline, so records exist and the cursor stayed null.
        expect(AttendanceRecord::where('tenant_id', $tenant->id)->exists())->toBeTrue();
        expect($device->fresh()->last_sync_at)->toBeNull();
    });

    it('requires a from_date when the window is from_date', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);
        $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
        $device = mockDevice($tenant->id, $branch->id);

        test()->postJson(
            "http://{$tenant->subdomain}.ethr.test/api/v1/devices/{$device->public_id}/import-history",
            ['window' => 'from_date']
        )->assertStatus(422)->assertJsonValidationErrors(['from_date']);
    });

    it('denies history import to employee-role users', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::EMPLOYEE], $tenant);
        $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
        $device = mockDevice($tenant->id, $branch->id);

        test()->postJson(
            "http://{$tenant->subdomain}.ethr.test/api/v1/devices/{$device->public_id}/import-history",
            ['window' => 'last_90']
        )->assertForbidden();
    });
});

describe('history import pagination', function () {
    it('drains a device across multiple pages, not just the first cap', function () {
        Carbon::setTestNow('2026-07-31 09:00:00');

        $tenant = createTenant();
        $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
        $employee = Employee::factory()->create([
            'tenant_id' => $tenant->id,
            'employee_code' => 'EMP-1',
            'badge_number' => '1001',
        ]);
        $device = mockDevice($tenant->id, $branch->id);

        // 150 check-ins on 150 distinct days — more than one 100-event page.
        $fake = new PaginatingFakeAdapter;
        for ($i = 0; $i < 150; $i++) {
            $fake->events[] = [
                'employee_badge' => '1001',
                'timestamp' => Carbon::now()->subDays(200 - $i)->setTime(8, 0)->format('Y-m-d\TH:i:sP'),
                'type' => 'check_in',
            ];
        }
        // Route the device's 'mock' adapter to the paginating fake for this test.
        app()->instance(MockAdapter::class, $fake);

        $since = Carbon::now()->subDays(210)->format('Y-m-d\TH:i:sP');
        runPull($device, 'history_import', $since);

        // All 150 punches imported → the loop pulled well past the first 100.
        expect(AttendanceRecord::where('tenant_id', $tenant->id)->where('employee_id', $employee->id)->count())
            ->toBe(150);
    });

    it('drains a full-history import, which has no start date, and leaves the cursor alone', function () {
        Carbon::setTestNow('2026-07-31 09:00:00');
        [$tenant, $device, $fake] = pagedDevice(badges: ['1001']);
        $fake->events = dailyCheckIns('1001', 150);

        // `full` is the one window with no `since`. It used to take the
        // incremental branch: one page, then last_sync_at moved to now.
        runPull($device, 'history_import', null);

        expect(AttendanceRecord::where('tenant_id', $tenant->id)->count())->toBe(150)
            ->and($device->fresh()->last_sync_at)->toBeNull();
    });

    it('keeps the punch one second after a page boundary on a device that filters strictly after since', function () {
        Carbon::setTestNow('2026-07-31 09:00:00');
        [$tenant, $device, $fake] = pagedDevice(badges: ['1001', '1002']);
        $fake->events = dailyCheckIns('1001', 100);
        // One second after the last event of the first page. Advancing the
        // cursor to "last event + 1s" and then filtering strictly after it
        // skipped exactly this punch.
        $fake->events[] = ['employee_badge' => '1002', 'timestamp' => secondAfter(end($fake->events)['timestamp'], 1), 'type' => 'check_in'];

        runPull($device, 'history_import', Carbon::now()->subDays(400)->format('Y-m-d\TH:i:sP'));

        expect(AttendanceRecord::where('tenant_id', $tenant->id)->count())->toBe(101);
    });

    it('keeps a same-second punch split by a page boundary on a device that filters from since inclusive', function () {
        Carbon::setTestNow('2026-07-31 09:00:00');
        [$tenant, $device, $fake] = pagedDevice(badges: ['1001', '1002']);
        $fake->inclusive = true;
        $fake->events = dailyCheckIns('1001', 100);
        // A second person in the very same second as the 100th punch: the page
        // ends between them.
        $fake->events[] = ['employee_badge' => '1002', 'timestamp' => end($fake->events)['timestamp'], 'type' => 'check_in'];

        runPull($device, 'history_import', Carbon::now()->subDays(400)->format('Y-m-d\TH:i:sP'));

        expect(AttendanceRecord::where('tenant_id', $tenant->id)->count())->toBe(101);

        // The device returns the boundary punch twice. It is one punch.
        $log = DeviceSyncLog::where('device_id', $device->id)->latest('id')->first();
        expect($log->events_found)->toBe(101)
            ->and($log->events_processed)->toBe(101)
            ->and($log->status)->toBe('success');
    });

    it('stops at its time budget and finishes in a follow-up job from where it stopped', function () {
        Carbon::setTestNow('2026-07-31 09:00:00');
        config(['devices.pull_time_budget_seconds' => 20]);
        [$tenant, $device, $fake] = pagedDevice(badges: ['1001']);
        $fake->events = dailyCheckIns('1001', 350);
        // Ten seconds a page: the budget runs out after the second page, long
        // before the 350 events do.
        $fake->secondsPerPull = 10;

        // The sync queue runs each continuation inline, so the whole chain
        // completes inside this call.
        runPull($device, 'history_import', Carbon::now()->subDays(400)->format('Y-m-d\TH:i:sP'));

        expect(AttendanceRecord::where('tenant_id', $tenant->id)->count())->toBe(350)
            ->and(DeviceSyncLog::where('device_id', $device->id)->count())->toBeGreaterThan(1)
            ->and($device->fresh()->last_sync_at)->toBeNull();
    });

    it('queues the continuation rather than running past its budget', function () {
        Carbon::setTestNow('2026-07-31 09:00:00');
        config(['devices.pull_time_budget_seconds' => 20]);
        [$tenant, $device, $fake] = pagedDevice(badges: ['1001']);
        $fake->events = dailyCheckIns('1001', 350);
        $fake->secondsPerPull = 10;

        Queue::fake();
        runPull($device, 'history_import', Carbon::now()->subDays(400)->format('Y-m-d\TH:i:sP'));

        // This job stopped with work left, and handed the rest on.
        expect(AttendanceRecord::where('tenant_id', $tenant->id)->count())->toBeLessThan(350);
        Queue::assertPushed(PullDeviceEventsJob::class, 1);
    });

    it('stops rather than loops when a whole page falls inside one second', function () {
        Carbon::setTestNow('2026-07-31 09:00:00');
        [$tenant, $device, $fake] = pagedDevice(badges: ['1001', '1002', '1003', '1004', '1005']);
        $fake->inclusive = true;
        $fake->pageSize = 3;
        $at = Carbon::now()->subDays(10)->setTime(8, 0)->format('Y-m-d\TH:i:sP');
        foreach (['1001', '1002', '1003', '1004', '1005'] as $badge) {
            $fake->events[] = ['employee_badge' => $badge, 'timestamp' => $at, 'type' => 'check_in'];
        }

        runPull($device, 'history_import', Carbon::now()->subDays(20)->format('Y-m-d\TH:i:sP'));

        // A time cursor cannot get past five punches in one second three at a
        // time. The run has to end, and has to say it is incomplete.
        $log = DeviceSyncLog::where('device_id', $device->id)->latest('id')->first();
        expect($fake->pulls)->toBeLessThan(10)
            ->and($log->status)->toBe('partial')
            ->and($log->error_message)->toContain('one second');
    });
});

it('stops at the moment the run began, even against a device that never runs dry', function () {
    Carbon::setTestNow('2026-07-31 09:00:00');
    [$tenant, $device] = pagedDevice(badges: ['1001']);

    // Always one more punch, an hour after whatever it is asked for: the shape
    // of MockAdapter, and of a device whose clock runs ahead.
    $endless = new class extends PaginatingFakeAdapter
    {
        public function pullEvents(Device $device, ?string $since = null): array
        {
            $this->pulls++;

            return [['employee_badge' => '1001', 'timestamp' => Carbon::parse($since)->addHour()->format('Y-m-d\TH:i:sP'), 'type' => 'check_in']];
        }
    };
    app()->instance(MockAdapter::class, $endless);

    Queue::fake();
    runPull($device, 'history_import', Carbon::now()->subHours(3)->format('Y-m-d\TH:i:sP'));

    // 06:00 asked → 07:00, 08:00, then 09:00, which is when the run began.
    expect($endless->pulls)->toBe(3);
    Queue::assertNothingPushed();
});

describe('incremental sync pagination', function () {
    it('drains a backlog larger than one page instead of dropping the overflow', function () {
        Carbon::setTestNow('2026-07-31 09:00:00');
        [$tenant, $device, $fake] = pagedDevice(badges: ['1001']);
        $device->update(['last_sync_at' => Carbon::now()->subDays(300)]);
        // A device back after a long outage: 250 punches waiting, 100 a page.
        $fake->events = dailyCheckIns('1001', 250);

        runPull($device, 'schedule');

        expect(AttendanceRecord::where('tenant_id', $tenant->id)->count())->toBe(250)
            ->and($device->fresh()->last_sync_at->toDateTimeString())->toBe('2026-07-31 09:00:00');
    });

    it('moves the cursor to when the sync began, so a punch during the sync is not skipped', function () {
        Carbon::setTestNow('2026-07-31 09:00:00');
        [$tenant, $device, $fake] = pagedDevice(badges: ['1001']);
        $device->update(['last_sync_at' => Carbon::now()->subDays(300)]);
        $fake->events = dailyCheckIns('1001', 250);
        $fake->secondsPerPull = 5;

        runPull($device, 'schedule');

        // Three pages took fifteen seconds. Anything the device recorded in
        // those seconds belongs to the next sync, so the cursor is the start.
        expect($device->fresh()->last_sync_at->toDateTimeString())->toBe('2026-07-31 09:00:00');
    });
});

it('matches a pulled punch to its shift on a worker with no tenant resolved', function () {
    // The job runs on a queue worker, where no tenant is resolved. Shift
    // matching goes through the tenant scope, so every pulled punch was
    // recorded with no shift — no lateness, no overtime — while every test
    // here passed, because they all ran with the tenant still set.
    Carbon::setTestNow('2026-07-31 09:00:00');

    $tenant = createTenant();
    $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'employee_code' => 'EMP-1', 'badge_number' => '1001']);
    $shift = Shift::factory()->default()->create(['tenant_id' => $tenant->id]);
    $device = mockDevice($tenant->id, $branch->id);

    app(CurrentTenant::class)->forget();
    runPull($device);
    app(CurrentTenant::class)->set($tenant);

    $record = AttendanceRecord::where('employee_id', $employee->id)->first();

    expect($record)->not->toBeNull()
        ->and($record->shift_id)->toBe($shift->id);
});
