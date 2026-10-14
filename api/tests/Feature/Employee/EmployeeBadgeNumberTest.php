<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\Employee;
use App\Services\CurrentTenant;

/**
 * The badge number is the id attendance terminals know a person by, and the
 * one DeviceCommandQueue sends to devices. A local ADMS run (2026-10-14) found
 * nothing could set it: not the forms, not the API, not the import — so a new
 * hire never reached a terminal on their own.
 */
function badgeSetup(): array
{
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
    $device = Device::factory()->create([
        'tenant_id' => $tenant->id, 'branch_id' => $branch->id,
        'adapter_type' => 'zkteco_adms', 'serial_number' => 'BADGE-SN-1',
        'connection_config' => ['mode' => 'adms'], 'commands_enabled' => true,
    ]);

    return [$tenant, $branch, $device];
}

it('takes a badge number on hire, returns it, and queues the person for the branch devices', function () {
    [$tenant, $branch, $device] = badgeSetup();

    test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/employees", [
        'name' => 'Selam Getachew',
        'hire_date' => '2026-10-01',
        'branch_id' => $branch->public_id,
        'badge_number' => '1042',
    ])->assertCreated()->assertJsonPath('badge_number', '1042');

    expect(DeviceCommand::where('device_id', $device->id)->where('type', 'upsert_user')->value('payload'))
        ->toMatchArray(['device_user_id' => '1042']);
});

it('changes a badge number from the profile, and the device follows', function () {
    [$tenant, $branch, $device] = badgeSetup();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'status' => 'confirmed']);

    test()->putJson("http://{$tenant->subdomain}.ethr.test/api/v1/employees/{$employee->public_id}", ['badge_number' => '2077'])
        ->assertOk()->assertJsonPath('badge_number', '2077');

    expect(DeviceCommand::where('device_id', $device->id)->latest('id')->value('payload'))
        ->toMatchArray(['device_user_id' => '2077']);
});

it('refuses a badge another employee holds, or one a terminal cannot store', function (string $badge) {
    [$tenant, $branch] = badgeSetup();
    Employee::factory()->create(['tenant_id' => $tenant->id, 'badge_number' => '555']);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id]);

    test()->putJson("http://{$tenant->subdomain}.ethr.test/api/v1/employees/{$employee->public_id}", ['badge_number' => $badge])
        ->assertUnprocessable()->assertJsonValidationErrors('badge_number');
})->with([
    'taken' => ['555'],
    'spaces' => ['12 34'],
    'too long' => [str_repeat('9', 33)],
]);

it('lets two organisations use the same badge number', function () {
    [$tenant, $branch] = badgeSetup();
    $other = Employee::factory()->create(['tenant_id' => createTenant()->id, 'badge_number' => '777']);
    app(CurrentTenant::class)->set($tenant);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id]);

    expect($other->badge_number)->toBe('777');
    test()->putJson("http://{$tenant->subdomain}.ethr.test/api/v1/employees/{$employee->public_id}", ['badge_number' => '777'])
        ->assertOk();
});
