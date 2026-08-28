<?php

declare(strict_types=1);

use App\Contracts\DeviceAdapter;
use App\Enums\UserRole;
use App\Jobs\PullDeviceEventsJob;
use App\Models\AttendanceRecord;
use App\Models\Branch;
use App\Models\Device;
use App\Models\Employee;
use App\Services\Attendance\AttendanceEngine;
use App\Services\Device\DeviceManager;
use App\Services\Device\MockAdapter;
use App\Services\Identity\IdentityResolver;
use Carbon\Carbon;

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
        $after = $since ? strtotime($since) : 0;

        $matching = array_values(array_filter(
            $this->events,
            fn (array $e): bool => strtotime($e['timestamp']) > $after,
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
});
