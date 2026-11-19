<?php

declare(strict_types=1);

use App\Enums\EmployeeStatus;
use App\Enums\UserRole;
use App\Jobs\RecordHeldPunchesJob;
use App\Models\AttendanceRecord;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Device;
use App\Models\Employee;
use App\Models\EmployeeExternalIdentity;
use App\Models\Tenant;
use App\Models\UnmatchedDevicePunch;
use App\Services\CurrentTenant;
use App\Services\Device\UnmatchedPunches;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

// Held punches of a badge are recorded without a click once the terminal number
// is written on exactly one current employee. A name alone never does.

afterEach(fn () => Carbon::setTestNow());

/** @return array{0: Tenant, 1: Device, 2: Employee} */
function autoSetup(string $deviceSerial = 'AUTO-SN-1'): array
{
    Carbon::setTestNow(Carbon::parse('2026-10-14 18:00:00', 'Africa/Addis_Ababa'));

    $tenant = createTenant(['timezone' => 'Africa/Addis_Ababa']);
    $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
    $device = Device::factory()->create([
        'tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'adapter_type' => 'zkteco_adms',
        'serial_number' => $deviceSerial, 'connection_config' => ['mode' => 'adms'], 'status' => 'online',
    ]);
    $employee = Employee::factory()->create([
        'tenant_id' => $tenant->id, 'branch_id' => $branch->id,
        'badge_number' => null, 'employee_code' => 'AUTO-EMP-'.$tenant->id, 'name' => 'Selam Tesfaye',
        'status' => EmployeeStatus::CONFIRMED,
    ]);

    return [$tenant, $device->fresh(), $employee];
}

function autoHold(Tenant $tenant, Device $device, int $days, string $badge = '7777'): void
{
    foreach (range(1, $days) as $n) {
        UnmatchedDevicePunch::create([
            'tenant_id' => $tenant->id, 'device_id' => $device->id, 'badge' => $badge, 'punch_type' => 'check_in',
            'occurred_raw' => sprintf('2026-10-%02d 08:00:00', $n), 'idempotency_key' => "auto-{$badge}-{$device->id}-{$n}",
            'status' => UnmatchedDevicePunch::PENDING,
        ]);
    }
}

function autoPending(): int
{
    return UnmatchedDevicePunch::withoutGlobalScopes()->where('status', UnmatchedDevicePunch::PENDING)->count();
}

function autoName(Tenant $tenant, Device $device, string $badge, string $name): void
{
    DB::table('device_enrollment_snapshots')->insert([
        'tenant_id' => $tenant->id, 'device_id' => $device->id, 'device_user_id' => $badge, 'name' => $name,
        'seen_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);
}

function autoSweep(): int
{
    app(CurrentTenant::class)->forget();
    $queued = RecordHeldPunchesJob::sweep();
    app(CurrentTenant::class)->forget();

    return $queued;
}

it('records every held punch of a badge once its number is written on the employee, earliest first', function () {
    [$tenant, $device, $employee] = autoSetup();
    autoHold($tenant, $device, 3);
    expect(autoSweep())->toBe(0);

    $employee->update(['badge_number' => '7777']);
    expect(autoSweep())->toBe(1);

    $rows = UnmatchedDevicePunch::withoutGlobalScopes()->orderBy('id')->get();
    expect($rows->pluck('status')->unique()->all())->toBe([UnmatchedDevicePunch::REPLAYED])
        ->and($rows->pluck('resolved_by')->filter()->count())->toBe(0)
        ->and($rows->pluck('employee_id')->unique()->all())->toBe([$employee->id])
        ->and(AttendanceRecord::withoutGlobalScopes()->where('employee_id', $employee->id)->count())->toBe(3)
        ->and(EmployeeExternalIdentity::withoutGlobalScopes()->where('employee_id', $employee->id)->whereNull('verified_at')->count())->toBe(1);

    $audit = AuditLog::withoutGlobalScopes()->where('action', 'device.held_punches_auto_recorded')->get();
    expect($audit)->toHaveCount(1);
});

it('records through the employee code as well', function () {
    [$tenant, $device, $employee] = autoSetup();
    autoHold($tenant, $device, 2, 'EC-9');
    $employee->update(['employee_code' => 'EC-9']);

    expect(autoSweep())->toBe(1)
        ->and(autoPending())->toBe(0)
        ->and(AttendanceRecord::withoutGlobalScopes()->where('employee_id', $employee->id)->count())->toBe(2);
});

it('records nothing when two current employees carry the number', function () {
    [$tenant, $device, $employee] = autoSetup();
    autoHold($tenant, $device, 2);
    $employee->update(['badge_number' => '7777']);
    Employee::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $employee->branch_id, 'badge_number' => '7777', 'name' => 'Dawit Bekele', 'status' => EmployeeStatus::CONFIRMED]);

    autoSweep();

    expect(autoPending())->toBe(2)
        ->and(AttendanceRecord::withoutGlobalScopes()->count())->toBe(0);
});

it('records nothing when the terminal calls the number by a name that shares no word with the employee', function () {
    [$tenant, $device, $employee] = autoSetup();
    autoHold($tenant, $device, 2);
    $employee->update(['badge_number' => '7777']);
    autoName($tenant, $device, '7777', 'Hanna Girma');

    autoSweep();

    expect(autoPending())->toBe(2)
        ->and(AttendanceRecord::withoutGlobalScopes()->count())->toBe(0);
});

it('records when the terminal name agrees with the employee', function () {
    [$tenant, $device, $employee] = autoSetup();
    autoHold($tenant, $device, 2);
    $employee->update(['badge_number' => '7777']);
    autoName($tenant, $device, '7777', 'Selam Tesfaye');

    autoSweep();

    expect(autoPending())->toBe(0);
});

it('never assigns on a name alone', function () {
    [$tenant, $device] = autoSetup();
    autoHold($tenant, $device, 2);
    autoName($tenant, $device, '7777', 'Selam Tesfaye');

    expect(autoSweep())->toBe(0)
        ->and(autoPending())->toBe(2);
});

it('records nothing for a leaver', function () {
    [$tenant, $device, $employee] = autoSetup();
    autoHold($tenant, $device, 2);
    $employee->update(['badge_number' => '7777', 'status' => EmployeeStatus::RESIGNED]);

    autoSweep();

    expect(autoPending())->toBe(2)
        ->and(AttendanceRecord::withoutGlobalScopes()->count())->toBe(0);
});

it('does not move a number a person confirmed for someone else', function () {
    [$tenant, $device, $employee] = autoSetup();
    $other = Employee::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $employee->branch_id, 'name' => 'Dawit Bekele', 'status' => EmployeeStatus::CONFIRMED]);
    EmployeeExternalIdentity::create([
        'tenant_id' => $tenant->id, 'employee_id' => $other->id, 'source_type' => $device->adapter_type,
        'source_ref' => 'device:'.$device->id, 'identifier_type' => 'device_user_id', 'identifier_value' => '7777',
        'confidence' => 1.0, 'verified_at' => now(),
    ]);
    autoHold($tenant, $device, 2);
    $employee->update(['badge_number' => '7777']);

    autoSweep();

    $mapping = EmployeeExternalIdentity::withoutGlobalScopes()->where('identifier_value', '7777')->sole();
    expect($mapping->employee_id)->toBe($other->id)
        ->and(AttendanceRecord::withoutGlobalScopes()->where('employee_id', $employee->id)->count())->toBe(0);
});

it('does not record for an employee of another organisation with the same number', function () {
    [$tenant, $device] = autoSetup();
    [, , $foreign] = autoSetup('AUTO-SN-2');
    $foreign->update(['badge_number' => '7777']);
    autoHold($tenant, $device, 2);
    app()->forgetScopedInstances();

    expect(autoSweep())->toBe(0)
        ->and(autoPending())->toBe(2)
        ->and(AttendanceRecord::withoutGlobalScopes()->count())->toBe(0);
});

it('leaves quietly while a manual assign holds the badge, and records nothing twice', function () {
    [$tenant, $device, $employee] = autoSetup();
    autoHold($tenant, $device, 2);
    $employee->update(['badge_number' => '7777']);

    $held = Cache::lock(UnmatchedPunches::lockKey($device, '7777'), 60);
    expect($held->get())->toBeTrue();
    autoSweep();
    expect(autoPending())->toBe(2);

    $held->release();
    autoSweep();
    autoSweep();

    expect(autoPending())->toBe(0)
        ->and(AttendanceRecord::withoutGlobalScopes()->where('employee_id', $employee->id)->count())->toBe(2)
        ->and(AuditLog::withoutGlobalScopes()->where('action', 'device.held_punches_auto_recorded')->count())->toBe(1);
});

it('does nothing for a device of another tenant in the payload', function () {
    [$tenant, $device, $employee] = autoSetup();
    $other = Tenant::factory()->create();
    autoHold($tenant, $device, 2);
    $employee->update(['badge_number' => '7777']);
    app(CurrentTenant::class)->forget();

    app()->call([new RecordHeldPunchesJob($device->id, $other->id, '7777'), 'handle']);

    expect(autoPending())->toBe(2);
});

it('says on the list which badges are being recorded', function () {
    [$tenant, $device, $employee] = autoSetup();
    autoHold($tenant, $device, 2);
    autoHold($tenant, $device, 1, '8888');
    $employee->update(['badge_number' => '7777']);
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    $rows = collect(test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/devices/unmatched-punches")
        ->assertOk()->json('data'))->keyBy('badge');

    expect($rows['7777']['recording_itself'])->toBeTrue()
        ->and($rows['8888']['recording_itself'])->toBeFalse();
});
