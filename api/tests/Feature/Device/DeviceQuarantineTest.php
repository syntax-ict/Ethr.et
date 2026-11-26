<?php

declare(strict_types=1);

use App\Enums\EmployeeStatus;
use App\Enums\UserRole;
use App\Jobs\CleanupExpiredDataJob;
use App\Jobs\ProcessDeviceInboundPunchesJob;
use App\Models\AttendanceRecord;
use App\Models\Branch;
use App\Models\Device;
use App\Models\DeviceInboundPunch;
use App\Models\Employee;
use App\Models\Tenant;
use Carbon\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;

/*
 * A punch the engine will not take is kept, with what the terminal sent, for a
 * person to replay or dismiss. Nothing a terminal sends is dropped.
 */

afterEach(fn () => Carbon::setTestNow());

/** @return array{0: Tenant, 1: Device, 2: Employee} */
function quarantineFixture(array $employee = []): array
{
    Carbon::setTestNow(Carbon::parse('2026-10-14 17:30:00', 'Africa/Addis_Ababa'));

    $tenant = createTenant(['timezone' => 'Africa/Addis_Ababa']);
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);
    $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
    $device = Device::factory()->hikvision()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'connection_config' => ['mode' => 'push'],
        'webhook_token' => 'quarantine-token',
    ]);
    $person = Employee::factory()->create($employee + ['tenant_id' => $tenant->id, 'badge_number' => '1001']);

    return [$tenant, $device, $person];
}

/** @param array<string, mixed> $body */
function quarantinePost(Tenant $tenant, array $body): TestResponse
{
    return test()->withHeader('X-Webhook-Token', 'quarantine-token')
        ->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/devices/webhook/hikvision", $body);
}

/** @return array<string, mixed> */
function quarantineEvent(string $badge, string $time, string $status = 'checkIn'): array
{
    return [
        'dateTime' => "2026-10-14T{$time}+03:00",
        'eventType' => 'AccessControllerEvent',
        'AccessControllerEvent' => [
            'majorEventType' => 5, 'subEventType' => 75,
            'employeeNoString' => $badge, 'attendanceStatus' => $status,
        ],
    ];
}

it('quarantines a punch of a leaver with the terminal raw event, and keeps it past cleanup', function () {
    [$tenant, $device] = quarantineFixture(['status' => EmployeeStatus::TERMINATED, 'termination_date' => '2026-09-01']);

    quarantinePost($tenant, quarantineEvent('1001', '08:00:00'))->assertOk();

    $row = DeviceInboundPunch::withoutGlobalScopes()->sole();
    expect($row->failed_at)->not->toBeNull()
        ->and($row->failure)->toBe('refused:employee_has_left')
        ->and($row->processed_at)->not->toBeNull()
        ->and($row->raw)->toContain('1001')
        ->and($row->error)->not->toBeNull()
        ->and($row->public_id)->toHaveLength(26)
        ->and(AttendanceRecord::withoutGlobalScopes()->count())->toBe(0);

    Carbon::setTestNow(now()->addDays(8));
    (new CleanupExpiredDataJob)->handle();

    expect(DeviceInboundPunch::withoutGlobalScopes()->count())->toBe(1);
});

it('quarantines a check-out with no open check-in', function () {
    [$tenant] = quarantineFixture();

    quarantinePost($tenant, quarantineEvent('1001', '08:00:00', 'checkOut'))->assertOk();

    expect(DeviceInboundPunch::withoutGlobalScopes()->sole()->failure)->toBe('refused:no_open_check_in');
});

it('quarantines a punch from a clock running ahead as clock_ahead', function () {
    [$tenant] = quarantineFixture();

    // 17:30 now; the terminal says 23:30.
    quarantinePost($tenant, quarantineEvent('1001', '23:30:00'))->assertOk();

    $row = DeviceInboundPunch::withoutGlobalScopes()->sole();
    expect($row->failure)->toBe('clock_ahead')
        ->and($row->failed_at)->not->toBeNull()
        ->and($row->raw)->not->toBeNull();
});

it('quarantines a punch that throws an unexpected error, and carries on with the next', function () {
    [$tenant, $device, $employee] = quarantineFixture();
    Queue::fake();

    quarantinePost($tenant, quarantineEvent('1001', '08:00:00'))->assertOk();
    quarantinePost($tenant, quarantineEvent('1001', '09:00:00'))->assertOk();

    $once = true;
    AttendanceRecord::creating(function () use (&$once): void {
        if ($once) {
            $once = false;
            throw new LogicException("boom\nwith a second line");
        }
    });

    app()->call([new ProcessDeviceInboundPunchesJob($device->id, $device->tenant_id), 'handle']);

    $rows = DeviceInboundPunch::withoutGlobalScopes()->orderBy('id')->get();
    expect($rows[0]->failure)->toBe('error')
        ->and($rows[0]->error)->toBe('boom with a second line')
        ->and($rows[0]->raw)->not->toBeNull()
        ->and($rows[1]->failed_at)->toBeNull()
        ->and($rows[1]->raw)->toBeNull();
});

it('clears the raw copy of a punch that was recorded', function () {
    [$tenant] = quarantineFixture();

    quarantinePost($tenant, quarantineEvent('1001', '08:00:00'))->assertOk();

    $row = DeviceInboundPunch::withoutGlobalScopes()->sole();
    expect($row->processed_at)->not->toBeNull()
        ->and($row->failed_at)->toBeNull()
        ->and($row->raw)->toBeNull();
});

it('does not date an event with no time now, and a resend is the same row', function () {
    [$tenant] = quarantineFixture();
    $event = quarantineEvent('1001', '08:00:00');
    unset($event['dateTime']);

    quarantinePost($tenant, $event)->assertOk();
    Carbon::setTestNow(now()->addMinutes(5));
    quarantinePost($tenant, $event)->assertOk();

    $row = DeviceInboundPunch::withoutGlobalScopes()->sole();
    expect($row->failure)->toBe('no_time')
        ->and($row->failed_at)->not->toBeNull()
        ->and(AttendanceRecord::withoutGlobalScopes()->count())->toBe(0);
});

it('caps a raw event at sixteen kilobytes', function () {
    $raw = DeviceInboundPunch::capRaw(['name' => str_repeat('a', 40000)]);

    expect(strlen((string) $raw))->toBeLessThanOrEqual(DeviceInboundPunch::RAW_MAX_BYTES)
        ->and($raw)->toEndWith('...[truncated]');
});
