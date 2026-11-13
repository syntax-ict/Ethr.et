<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\Device;
use App\Models\Tenant;
use App\Services\Device\WebhookRejections;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

/*
 * A terminal whose calls are refused used to leave a log line and nothing
 * else, so its device page said "Nothing received from this terminal yet",
 * exactly as for a terminal that never called (2026-11-13). The refusal is
 * now kept on the device it can be traced to, and only there.
 */

afterEach(fn () => Carbon::setTestNow());

/** @return array{0: Tenant, 1: Device} */
function refusedTerminal(array $attributes = []): array
{
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);
    $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);

    $device = Device::factory()->hikvision()->create(array_merge([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'webhook_token' => Device::generateWebhookToken(),
        // A terminal that sends to ETHR: anything it sends is heard.
        'connection_config' => [],
        'status' => 'pending',
        'last_sync_at' => null,
    ], $attributes));

    return [$tenant, $device];
}

function pushAs(Tenant $tenant, ?string $token, array $event = []): void
{
    $query = $token === null ? '' : '?token='.$token;

    test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/devices/webhook/hikvision{$query}", [
        'dateTime' => '2026-11-13T09:00:00+03:00',
        'AccessControllerEvent' => $event + ['employeeNoString' => '7301', 'attendanceStatus' => 'checkIn'],
    ]);
}

function traceOn(Tenant $tenant, Device $device): mixed
{
    return test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/devices/{$device->public_id}")
        ->assertOk()
        ->json('webhook_rejected');
}

it('names a terminal still sending the token it had before the token was regenerated', function () {
    [$tenant, $device] = refusedTerminal();
    $old = $device->webhook_token;

    test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/devices/{$device->public_id}/regenerate-token")->assertOk();
    pushAs($tenant, $old);

    $trace = traceOn($tenant, $device);
    expect($trace['reason'])->toBe('token_retired')
        ->and($trace['at'])->not->toBeNull()
        ->and(json_encode($trace))->not->toContain($old);
});

it('names a terminal that sent only the start of its token', function () {
    [$tenant, $device] = refusedTerminal();

    pushAs($tenant, substr((string) $device->webhook_token, 0, 40));

    expect(traceOn($tenant, $device)['reason'])->toBe('token_truncated');
});

it('names a terminal that called by its serial without the token it has', function () {
    [$tenant, $device] = refusedTerminal(['serial_number' => 'HIK-SN-77']);

    pushAs($tenant, null, ['deviceSerialNo' => 'HIK-SN-77']);

    expect(traceOn($tenant, $device)['reason'])->toBe('token_missing');
});

it('names a token-less terminal calling from an address not on its list', function () {
    [$tenant, $device] = refusedTerminal([
        'serial_number' => 'HIK-SN-78',
        'webhook_token' => null,
        'webhook_ip_allowlist' => ['10.9.9.9'],
    ]);

    pushAs($tenant, null, ['deviceSerialNo' => 'HIK-SN-78']);

    $trace = traceOn($tenant, $device);
    expect($trace['reason'])->toBe('ip_not_allowed')
        ->and($trace['ip'])->toBe('127.0.0.1');
});

it('traces nothing it would have to guess', function (string $token) {
    [$tenant, $device] = refusedTerminal(['webhook_token' => str_repeat('a', 64)]);

    pushAs($tenant, $token);

    expect(traceOn($tenant, $device))->toBeNull();
})->with([
    'a token mistyped from the start' => [str_repeat('b', 64)],
    'a wildcard' => ['%'],
    'a wildcard after a real prefix' => [str_repeat('a', 16).'%'],
    'an underscore' => [str_repeat('_', 20)],
    'a prefix too short to name one device' => [str_repeat('a', 15)],
    'a prefix that is not hex' => [str_repeat('A', 20)],
]);

it('traces a shared prefix to neither device, in either organisation', function () {
    $prefix = str_repeat('c', 20);
    [$tenantA, $deviceA] = refusedTerminal(['webhook_token' => $prefix.str_repeat('1', 44)]);
    $tenantB = createTenant();
    $deviceB = Device::factory()->hikvision()->create([
        'tenant_id' => $tenantB->id,
        'branch_id' => Branch::factory()->create(['tenant_id' => $tenantB->id])->id,
        'webhook_token' => $prefix.str_repeat('2', 44),
    ]);

    pushAs($tenantA, $prefix);

    expect(WebhookRejections::last($deviceA))->toBeNull()
        ->and(WebhookRejections::last($deviceB))->toBeNull();
});

it('keeps one organisation\'s refusals off another organisation\'s device', function () {
    [$tenantA, $deviceA] = refusedTerminal();
    pushAs($tenantA, substr((string) $deviceA->webhook_token, 0, 32));

    [$tenantB, $deviceB] = refusedTerminal();

    expect(traceOn($tenantB, $deviceB))->toBeNull();
    test()->getJson("http://{$tenantB->subdomain}.ethr.test/api/v1/devices/{$deviceA->public_id}")->assertNotFound();
});

it('stops showing a refusal once the terminal has been heard from since', function () {
    Carbon::setTestNow('2026-11-13 06:00:00');
    [$tenant, $device] = refusedTerminal();
    $token = (string) $device->webhook_token;

    pushAs($tenant, substr($token, 0, 32));
    expect(traceOn($tenant, $device))->not->toBeNull();

    Carbon::setTestNow('2026-11-13 06:05:00');
    pushAs($tenant, $token);

    expect(traceOn($tenant, $device))->toBeNull();
});

it('writes a terminal retrying every few seconds once a minute, not on every call', function () {
    Carbon::setTestNow('2026-11-13 06:00:00');
    [$tenant, $device] = refusedTerminal();
    $cut = substr((string) $device->webhook_token, 0, 32);

    pushAs($tenant, $cut);
    Carbon::setTestNow('2026-11-13 06:00:30');
    pushAs($tenant, $cut);
    expect(Carbon::parse(traceOn($tenant, $device)['at'])->toDateTimeString())->toBe('2026-11-13 06:00:00');

    Carbon::setTestNow('2026-11-13 06:01:30');
    pushAs($tenant, $cut);
    expect(Carbon::parse(traceOn($tenant, $device)['at'])->toDateTimeString())->toBe('2026-11-13 06:01:30');
});

it('shows the refusal to an organisation admin only', function () {
    [$tenant, $device] = refusedTerminal();
    pushAs($tenant, substr((string) $device->webhook_token, 0, 32));

    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/devices/{$device->public_id}")
        ->assertOk()
        ->assertJsonMissingPath('webhook_rejected');
});

it('never throws when the trace cannot be kept or read', function () {
    [, $device] = refusedTerminal();
    Cache::shouldReceive('get')->andThrow(new RuntimeException('cache down'));
    Cache::shouldReceive('put')->andThrow(new RuntimeException('cache down'));

    WebhookRejections::retire($device, 'old');
    WebhookRejections::traceToken('hikvision', substr((string) $device->webhook_token, 0, 32), '127.0.0.1');
    WebhookRejections::record($device, 'token_missing', '127.0.0.1');

    expect(WebhookRejections::last($device))->toBeNull();
});
