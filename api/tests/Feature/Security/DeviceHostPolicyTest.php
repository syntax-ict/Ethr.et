<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\Device;
use App\Rules\ExternalUrl;
use App\Services\Device\DeviceManager;
use App\Support\OutboundHost;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;

/*
 * A device's address is chosen by a tenant admin, and the server connects to it
 * from devices:sync, the pull job and GET /devices/{id}/status, whose response
 * carries the connection error. Nothing checked the address, so a tenant could
 * point the server at its own loopback and private network and learn which
 * ports answer.
 *
 * phpunit.xml turns private hosts ON so the adapter tests can address fake
 * devices on 10.x; every test here turns them OFF, which is the shared-hosting
 * production setting.
 */

beforeEach(function () {
    config(['devices.allow_private_hosts' => false]);
});

function deviceTenant(): array
{
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);
    $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);

    return [$tenant, $branch];
}

function registerDevice($tenant, $branch, string $adapter, array $config)
{
    return test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/devices", [
        'name' => 'Gate',
        'adapter_type' => $adapter,
        'branch_public_id' => $branch->public_id,
        'connection_config' => $config,
    ]);
}

it('refuses a vendor device at a loopback or private address', function (string $ip) {
    [$tenant, $branch] = deviceTenant();

    registerDevice($tenant, $branch, 'zkteco', ['ip' => $ip, 'port' => 80])
        ->assertStatus(422)
        ->assertJsonValidationErrors('connection_config');
})->with(['127.0.0.1', '10.0.0.5', '192.168.1.201', '169.254.169.254', '::1', 'localhost']);

it('refuses an ip field that smuggles a port, path or userinfo into the URL', function (string $ip) {
    [$tenant, $branch] = deviceTenant();

    registerDevice($tenant, $branch, 'hikvision', ['ip' => $ip, 'port' => 80])
        ->assertStatus(422)
        ->assertJsonValidationErrors('connection_config');
})->with(['8.8.8.8:8443/admin#', 'user@8.8.8.8', 'http://8.8.8.8', '8.8.8.8/x']);

it('accepts a vendor device at a public address', function () {
    [$tenant, $branch] = deviceTenant();

    registerDevice($tenant, $branch, 'zkteco', ['ip' => '8.8.8.8', 'port' => 4370])
        ->assertStatus(201);
});

it('refuses a generic device whose base_url is internal', function () {
    [$tenant, $branch] = deviceTenant();

    registerDevice($tenant, $branch, 'generic', ['base_url' => 'http://127.0.0.1:8443'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('connection_config');
});

it('refuses a generic path that would change the host', function (string $path) {
    [$tenant, $branch] = deviceTenant();

    registerDevice($tenant, $branch, 'generic', [
        'base_url' => 'https://8.8.8.8',
        'status_path' => $path,
    ])->assertStatus(422)->assertJsonValidationErrors('connection_config');
})->with(['@127.0.0.1/x', '//127.0.0.1/x', 'status', '/a\\@b']);

it('updates a generic device, which the old rules made impossible', function () {
    [$tenant, $branch] = deviceTenant();
    $device = Device::factory()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'adapter_type' => 'generic',
        'connection_config' => ['base_url' => 'https://8.8.8.8'],
    ]);

    // UpdateDeviceRequest required ip and port whenever connection_config was
    // sent, and a generic device has neither.
    test()->putJson("http://{$tenant->subdomain}.ethr.test/api/v1/devices/{$device->public_id}", [
        'connection_config' => ['base_url' => 'https://8.8.4.4', 'mapping' => ['user_id' => 'id']],
    ])->assertOk();

    expect($device->fresh()->connection_config['base_url'])->toBe('https://8.8.4.4');
});

it('refuses to update a device to an internal address', function () {
    [$tenant, $branch] = deviceTenant();
    $device = Device::factory()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'adapter_type' => 'zkteco',
        'connection_config' => ['ip' => '8.8.8.8', 'port' => 4370],
    ]);

    test()->putJson("http://{$tenant->subdomain}.ethr.test/api/v1/devices/{$device->public_id}", [
        'connection_config' => ['ip' => '127.0.0.1', 'port' => 8443],
    ])->assertStatus(422)->assertJsonValidationErrors('connection_config');
});

it('does not connect to an internal address stored before the rule existed', function () {
    [$tenant, $branch] = deviceTenant();
    Http::fake();

    // Written directly, as a pre-existing row would be — validation never ran.
    $device = Device::factory()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'adapter_type' => 'zkteco',
        'connection_config' => ['ip' => '127.0.0.1', 'port' => 8443],
    ]);

    $response = test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/devices/{$device->public_id}/status");

    $response->assertOk()->assertJsonPath('status', 'offline');
    Http::assertNothingSent();
});

it('allows private addresses when the deployment opts in', function () {
    config(['devices.allow_private_hosts' => true]);
    [$tenant, $branch] = deviceTenant();

    registerDevice($tenant, $branch, 'zkteco', ['ip' => '192.168.1.201', 'port' => 4370])
        ->assertStatus(201);
});

it('still refuses a malformed address when private addresses are allowed', function () {
    config(['devices.allow_private_hosts' => true]);
    [$tenant, $branch] = deviceTenant();

    registerDevice($tenant, $branch, 'zkteco', ['ip' => '192.168.1.201:8443/admin#', 'port' => 4370])
        ->assertStatus(422);
});

// ── The host check both webhooks and devices share ──

it('treats bracketed and IPv4-mapped IPv6 loopback as internal', function (string $host) {
    expect(OutboundHost::isInternal($host))->toBeTrue();
})->with(['[::1]', '::1', '::ffff:127.0.0.1', '[::ffff:127.0.0.1]', '2130706433', '0x7f000001', 'localhost.', 'printer.local']);

it('closes the bracketed-IPv6 bypass in the webhook URL rule', function () {
    // parse_url returns "[::1]" with its brackets, which the old inline check
    // neither recognised as ::1 nor as an IP, so this passed as external.
    $validator = Validator::make(['url' => 'http://[::1]/steal'], ['url' => [new ExternalUrl]]);

    expect($validator->fails())->toBeTrue();
});

it('resolves an adapter for a device regardless of host policy', function () {
    // The guard lives at request time, not construction: listing and viewing a
    // device with a bad stored address must keep working so it can be fixed.
    $device = Device::factory()->make(['adapter_type' => 'zkteco', 'connection_config' => ['ip' => '127.0.0.1', 'port' => 1]]);

    expect(app(DeviceManager::class)->adapter($device))->not->toBeNull();
});
