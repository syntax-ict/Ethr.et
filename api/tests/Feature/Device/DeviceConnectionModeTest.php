<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\Device;
use App\Models\Tenant;
use Illuminate\Testing\TestResponse;

/*
 * The setup dialog and the actions menu follow what the device resource says
 * about how the terminal connects (device setup upgrade, PR 1). The resource
 * names the mode and says whether a token is part of it; the token itself is
 * still issued to every device, so a screen changes and nothing stored does.
 */

/** @return array{0: Tenant, 1: Branch} */
function modeOrganisation(): array
{
    $tenant = createTenant(['timezone' => 'Africa/Addis_Ababa']);
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    return [$tenant, Branch::factory()->create(['tenant_id' => $tenant->id])];
}

function modeDevice(Tenant $tenant, Branch $branch, string $adapter, array $config, array $attributes = []): Device
{
    return Device::factory()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'adapter_type' => $adapter,
        'connection_config' => $config,
        'webhook_ip_allowlist' => ['127.0.0.1'],
        ...$attributes,
    ]);
}

function modeShow(Tenant $tenant, Device $device): TestResponse
{
    return test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/devices/{$device->public_id}")->assertOk();
}

dataset('connection modes', [
    'hikvision with no address' => ['hikvision', ['mode' => 'push'], 'push', true],
    'hikvision with an address' => ['hikvision', ['ip' => '203.0.113.10', 'port' => 80], 'pull', true],
    'zkteco with no address' => ['zkteco', [], 'push', true],
    'zkteco with an address' => ['zkteco', ['ip' => '203.0.113.11', 'port' => 4370], 'pull', true],
    'suprema with no address' => ['suprema', [], 'push', true],
    'suprema with an address' => ['suprema', ['ip' => '203.0.113.12', 'port' => 443], 'pull', true],
    'zkteco over adms' => ['zkteco_adms', ['mode' => 'adms'], 'adms', false],
    'the simulator' => ['mock', [], 'pull', false],
]);

it('names how each kind of terminal connects, and whether a token is part of it', function (string $adapter, array $config, string $mode, bool $usesToken) {
    [$tenant, $branch] = modeOrganisation();
    $attributes = $adapter === 'zkteco_adms' ? ['serial_number' => 'SN-MODE-1'] : [];
    $device = modeDevice($tenant, $branch, $adapter, $config, $attributes);

    modeShow($tenant, $device)
        ->assertJsonPath('connection_mode', $mode)
        ->assertJsonPath('uses_webhook_token', $usesToken);
})->with('connection modes');

it('says the same on the list as on the device', function () {
    [$tenant, $branch] = modeOrganisation();
    $push = modeDevice($tenant, $branch, 'hikvision', ['mode' => 'push'], ['name' => 'Push door']);
    $adms = modeDevice($tenant, $branch, 'zkteco_adms', ['mode' => 'adms'], ['name' => 'Adms door', 'serial_number' => 'SN-MODE-2']);

    $rows = collect(test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/devices")->assertOk()->json('data'))
        ->keyBy('public_id');

    expect($rows[$push->public_id]['connection_mode'])->toBe('push')
        ->and($rows[$push->public_id]['uses_webhook_token'])->toBeTrue()
        ->and($rows[$adms->public_id]['connection_mode'])->toBe('adms')
        ->and($rows[$adms->public_id]['uses_webhook_token'])->toBeFalse();
});

it('keeps issuing a token to every device, ADMS included, whatever the screen shows', function () {
    [$tenant, $branch] = modeOrganisation();
    $url = "http://{$tenant->subdomain}.ethr.test/api/v1/devices";

    $created = test()->postJson($url, [
        'name' => 'Gate adms',
        'adapter_type' => 'zkteco_adms',
        'serial_number' => 'SN-MODE-3',
        'branch_public_id' => $branch->public_id,
        'connection_config' => ['mode' => 'adms'],
    ])->assertCreated();

    expect($created->json('connection_mode'))->toBe('adms')
        ->and($created->json('uses_webhook_token'))->toBeFalse()
        ->and(Device::where('serial_number', 'SN-MODE-3')->firstOrFail()->hasWebhookToken())->toBeTrue();
});

it('reads a terminal that moves from push to pull as a pulled one', function () {
    [$tenant, $branch] = modeOrganisation();
    $device = modeDevice($tenant, $branch, 'hikvision', ['mode' => 'push']);

    modeShow($tenant, $device)->assertJsonPath('connection_mode', 'push');

    test()->putJson("http://{$tenant->subdomain}.ethr.test/api/v1/devices/{$device->public_id}", [
        'connection_config' => ['ip' => '203.0.113.20', 'port' => 80],
    ])->assertOk()
        ->assertJsonPath('connection_mode', 'pull')
        ->assertJsonPath('uses_webhook_token', true);
});
