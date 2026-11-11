<?php

declare(strict_types=1);

use App\Enums\EmployeeStatus;
use App\Models\Branch;
use App\Models\Device;
use App\Models\Employee;
use App\Models\EmployeeExternalIdentity;
use App\Services\Device\DevicePunchRecorder;
use App\Services\Identity\IdentityResolver;
use App\Services\Identity\IdentitySignals;

/*
 * Whose punch a device number is. The identity rules grew a contradiction check,
 * an archived verdict and a guarded link (IdentityResolverContradictionTest);
 * none of that may change who a punch belongs to. A punch carries a number and
 * nothing else, so each case here is that number against the roster.
 */

function punchDevice(int $tenantId): Device
{
    return Device::factory()->create([
        'tenant_id' => $tenantId,
        'branch_id' => Branch::factory()->create(['tenant_id' => $tenantId])->id,
        'adapter_type' => 'mock',
        'status' => 'online',
    ]);
}

function whosePunch(Device $device, string $badge): ?Employee
{
    return app(DevicePunchRecorder::class)->employee($device, $badge);
}

it('belongs to the employee who holds the number as a badge, and remembers it unconfirmed', function () {
    $tenant = createTenant();
    $emp = Employee::factory()->create(['tenant_id' => $tenant->id, 'employee_code' => 'EMP-1', 'badge_number' => '1001', 'name' => 'Abebe Kebede']);
    $device = punchDevice($tenant->id);

    expect(whosePunch($device, '1001')?->id)->toBe($emp->id);

    $mapping = EmployeeExternalIdentity::where('tenant_id', $tenant->id)->where('identifier_value', '1001')->sole();

    expect($mapping->employee_id)->toBe($emp->id)
        ->and($mapping->source_ref)->toBe('device:'.$device->id)
        ->and($mapping->verified_at)->toBeNull();
});

it('belongs to the employee whose employee number it is', function () {
    $tenant = createTenant();
    $emp = Employee::factory()->create(['tenant_id' => $tenant->id, 'employee_code' => '12']);

    expect(whosePunch(punchDevice($tenant->id), '12')?->id)->toBe($emp->id);
});

it('is held when the number is one person\'s employee number and another\'s badge', function () {
    $tenant = createTenant();
    Employee::factory()->create(['tenant_id' => $tenant->id, 'employee_code' => '12']);
    Employee::factory()->create(['tenant_id' => $tenant->id, 'employee_code' => 'EMP-2', 'badge_number' => '12']);

    expect(whosePunch(punchDevice($tenant->id), '12'))->toBeNull();
});

it('is held when nobody has the number', function () {
    $tenant = createTenant();
    Employee::factory()->create(['tenant_id' => $tenant->id, 'employee_code' => 'EMP-1', 'badge_number' => '1001']);

    expect(whosePunch(punchDevice($tenant->id), '4040'))->toBeNull();
});

it('is held when the only holder is archived', function () {
    $tenant = createTenant();
    Employee::factory()->create(['tenant_id' => $tenant->id, 'employee_code' => 'EMP-1', 'badge_number' => '1001'])->delete();

    expect(whosePunch(punchDevice($tenant->id), '1001'))->toBeNull();
});

it('belongs to the current holder when an archived employee once had the same number', function () {
    $tenant = createTenant();
    Employee::factory()->create(['tenant_id' => $tenant->id, 'employee_code' => 'OLD-1', 'badge_number' => '1001'])->delete();
    $current = Employee::factory()->create(['tenant_id' => $tenant->id, 'employee_code' => '1001']);

    expect(whosePunch(punchDevice($tenant->id), '1001')?->id)->toBe($current->id);
});

it('still belongs to the remembered employee after their badge number changes', function () {
    $tenant = createTenant();
    $emp = Employee::factory()->create(['tenant_id' => $tenant->id, 'employee_code' => 'EMP-1', 'badge_number' => '1001']);
    $device = punchDevice($tenant->id);

    whosePunch($device, '1001');
    $emp->update(['badge_number' => '2002']);

    expect(whosePunch($device, '1001')?->id)->toBe($emp->id);
});

it('follows the match when the remembered, unconfirmed holder is archived', function () {
    $tenant = createTenant();
    $old = Employee::factory()->create(['tenant_id' => $tenant->id, 'employee_code' => 'EMP-1', 'badge_number' => '1001']);
    $device = punchDevice($tenant->id);
    whosePunch($device, '1001');

    $old->delete();
    $next = Employee::factory()->create(['tenant_id' => $tenant->id, 'employee_code' => 'EMP-2', 'badge_number' => '1001']);

    expect(whosePunch($device, '1001')?->id)->toBe($next->id)
        ->and(EmployeeExternalIdentity::where('tenant_id', $tenant->id)->where('identifier_value', '1001')->sole()->employee_id)->toBe($next->id);
});

it('is still resolved for someone who has left, so the engine can refuse it', function () {
    $tenant = createTenant();
    $emp = Employee::factory()->create(['tenant_id' => $tenant->id, 'employee_code' => 'EMP-1', 'badge_number' => '1001', 'status' => EmployeeStatus::RESIGNED]);

    expect(whosePunch(punchDevice($tenant->id), '1001')?->id)->toBe($emp->id);
});

it('never names an employee of another organisation', function () {
    $other = createTenant();
    Employee::factory()->create(['tenant_id' => $other->id, 'employee_code' => 'EMP-1', 'badge_number' => '1001']);

    $mine = createTenant();

    expect(whosePunch(punchDevice($mine->id), '1001'))->toBeNull();
});

it('reads a punch and a pull as the same signals', function () {
    $tenant = createTenant();
    $device = punchDevice($tenant->id);

    $signals = IdentitySignals::forDeviceUser($device, '1001');

    expect($signals->employeeCode)->toBe('1001')
        ->and($signals->badgeNumber)->toBe('1001')
        ->and($signals->name)->toBeNull()
        ->and($signals->sourceType)->toBe('mock')
        ->and($signals->sourceRef)->toBe('device:'.$device->id)
        ->and($signals->identifierType)->toBe('device_user_id')
        ->and($signals->identifierValue)->toBe('1001')
        ->and(app(IdentityResolver::class)->resolve($tenant->id, $signals)->outcome)->toBe('new');
});
