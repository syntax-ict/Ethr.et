<?php

declare(strict_types=1);

use App\Models\Branch;
use App\Models\Device;
use App\Services\Device\DeviceHost;
use App\Services\Device\GenericHttpAdapter;
use App\Services\Device\HikvisionAdapter;
use App\Services\Device\SupremaAdapter;
use App\Services\Device\ZktecoAdapter;
use App\Support\OutboundHost;
use Illuminate\Support\Facades\Http;

/*
 * A device adapter connects to the addresses checked for its host, as webhook
 * delivery does. The host was checked and then looked up again by the HTTP
 * client, so a name whose DNS answer changed in between reached an address
 * that was never checked.
 */

beforeEach(function () {
    config(['devices.allow_private_hosts' => false]);
    OutboundHost::resolveUsing(fn (string $host): array => match ($host) {
        'gate.example.et' => ['93.184.216.34'],
        'inside.example.et' => ['10.0.0.7'],
        default => [],
    });
});

afterEach(fn () => OutboundHost::resolveUsing(null));

function pinnedDevice(string $adapter, array $config): Device
{
    $tenant = createTenant();
    $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);

    return Device::factory()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'adapter_type' => $adapter,
        'connection_config' => $config,
    ]);
}

it('connects each vendor adapter to the address it checked', function (string $class, string $adapter, array $config, string $pin) {
    $sent = new ArrayObject;
    Http::fake(function ($request, array $options) use ($sent) {
        $sent['resolve'] = $options['curl'][CURLOPT_RESOLVE] ?? null;

        return Http::response([], 200);
    });

    app($class)->getStatus(pinnedDevice($adapter, $config));

    expect($sent['resolve'] ?? null)->toBe([$pin]);
})->with([
    'Hikvision' => [HikvisionAdapter::class, 'hikvision', ['ip' => 'gate.example.et', 'port' => '80', 'username' => 'admin', 'password' => 'x'], 'gate.example.et:80:93.184.216.34'],
    'ZKTeco' => [ZktecoAdapter::class, 'zkteco', ['ip' => 'gate.example.et', 'port' => '8090'], 'gate.example.et:8090:93.184.216.34'],
    'Suprema' => [SupremaAdapter::class, 'suprema', ['ip' => 'gate.example.et', 'port' => 443, 'api_key' => 'k'], 'gate.example.et:443:93.184.216.34'],
    'generic' => [GenericHttpAdapter::class, 'generic_http', ['base_url' => 'https://gate.example.et:9443/api'], 'gate.example.et:9443:93.184.216.34'],
]);

it('refuses a name with an internal answer and sends nothing', function () {
    Http::fake();

    expect(fn () => DeviceHost::connectOptions('inside.example.et', 80))->toThrow(RuntimeException::class, __('device.host_internal'));

    app(ZktecoAdapter::class)->getStatus(pinnedDevice('zkteco', ['ip' => 'inside.example.et', 'port' => '80']));
    Http::assertNothingSent();
});

it('refuses a name that resolves to nothing rather than letting the client look it up', function () {
    expect(fn () => DeviceHost::connectOptions('nowhere.example.et', 80))->toThrow(RuntimeException::class, __('device.host_unresolved'));
});

it('pins nothing for an IP address, or where private hosts are allowed', function () {
    expect(DeviceHost::connectOptions('93.184.216.34', 80))->toBe([]);

    config(['devices.allow_private_hosts' => true]);
    expect(DeviceHost::connectOptions('inside.example.et', 80))->toBe([]);
});
