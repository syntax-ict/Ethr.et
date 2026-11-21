<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Jobs\ExportTenantDataJob;
use App\Models\Branch;
use App\Models\Device;
use App\Models\Tenant;
use App\Services\Device\WebhookRejections;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

/*
 * A terminal's webhook token is kept as a hash (biometric upgrade, PR 3):
 * database backups go off-host, and a copy of one must not let anyone post
 * punches into an organisation. These tests pin what that changed and what it
 * must not: the same address keeps working, the token is shown once, and a
 * device that has a token is never reachable without it.
 */

afterEach(fn () => Carbon::setTestNow());

function hashHost(Tenant $tenant): string
{
    return "http://{$tenant->subdomain}.ethr.test";
}

/** @return array{0: Tenant, 1: Device, 2: string} */
function hashedTerminal(array $attributes = [], UserRole $role = UserRole::TENANT_ADMIN): array
{
    $tenant = createTenant();
    actingAsUser(['role' => $role], $tenant);
    $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
    $token = Device::generateWebhookToken();

    $device = Device::factory()->hikvision()->create(array_merge([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'webhook_token' => $token,
        'connection_config' => [],
        'status' => 'pending',
        'last_sync_at' => null,
    ], $attributes));

    return [$tenant, $device, $token];
}

function pushWith(Tenant $tenant, ?string $token, string $serial = 'SN-1'): TestResponse
{
    $query = $token === null ? '' : '?token='.$token;
    app()->forgetScopedInstances();

    return test()->postJson(hashHost($tenant)."/api/v1/devices/webhook/hikvision{$query}", [
        'dateTime' => '2026-11-13T09:00:00+03:00',
        'AccessControllerEvent' => ['employeeNoString' => '7301', 'attendanceStatus' => 'checkIn', 'deviceSerialNo' => $serial],
    ]);
}

function regenerate(Tenant $tenant, Device $device, ?string $reason = null): TestResponse
{
    return test()->postJson(hashHost($tenant)."/api/v1/devices/{$device->public_id}/regenerate-token", $reason === null ? [] : ['reason' => $reason]);
}

function rawDevice(Device $device): object
{
    return DB::table('devices')->where('id', $device->id)->first();
}

it('stores a hash, a display prefix and a head hash, and no plaintext', function () {
    [, $device, $token] = hashedTerminal();
    $row = rawDevice($device);

    expect($row->webhook_token)->toBeNull()
        ->and($row->webhook_token_hash)->toBe(hash('sha256', $token))
        ->and($row->webhook_token_prefix)->toBe(substr($token, 0, 8))
        ->and($row->webhook_token_head_hash)->toBe(hash('sha256', substr($token, 0, 16)))
        ->and(json_encode($row))->not->toContain($token);
    expect(Device::withoutGlobalScope('tenant')->find($device->id)->hasWebhookToken())->toBeTrue();
});

it('clears all three columns when the token is set to null', function () {
    [, $device] = hashedTerminal();

    $device->update(['webhook_token' => null]);
    $row = rawDevice($device);

    expect($row->webhook_token_hash)->toBeNull()
        ->and($row->webhook_token_prefix)->toBeNull()
        ->and($row->webhook_token_head_hash)->toBeNull();
});

it('keeps an existing plaintext device working with the same address after the migration, and a re-run changes nothing', function () {
    [$tenant, $device, $token] = hashedTerminal();
    $migration = require base_path('database/migrations/2026_11_21_000010_hash_device_webhook_tokens.php');

    // The row as production holds it before the release: plaintext, no hash.
    $migration->down();
    DB::table('devices')->where('id', $device->id)->update(['webhook_token' => $token]);

    $migration->up();
    $row = rawDevice($device);

    expect($row->webhook_token)->toBeNull()
        ->and($row->webhook_token_hash)->toBe(hash('sha256', $token))
        ->and($row->webhook_token_prefix)->toBe(substr($token, 0, 8));

    pushWith($tenant, $token)->assertStatus(200);
    pushWith($tenant, 'f'.substr($token, 1))->assertStatus(401);

    // Nothing is left for a second pass to select.
    expect(DB::table('devices')->whereNotNull('webhook_token')->whereNull('webhook_token_hash')->count())->toBe(0);
});

it('refuses a serial-only call from an allowlisted address to a device that has a token', function () {
    [$tenant, $device] = hashedTerminal(['serial_number' => 'SN-ALLOW', 'webhook_ip_allowlist' => ['203.0.113.7']]);

    app()->forgetScopedInstances();
    test()->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])
        ->postJson(hashHost($tenant).'/api/v1/devices/webhook/hikvision', [
            'dateTime' => '2026-11-13T09:00:00+03:00',
            'AccessControllerEvent' => ['employeeNoString' => '7301', 'attendanceStatus' => 'checkIn', 'deviceSerialNo' => 'SN-ALLOW'],
        ])->assertStatus(401);

    expect(WebhookRejections::last($device)['reason'] ?? null)->toBe('token_missing');
});

it('still accepts a token-less device from its allowlisted address', function () {
    [$tenant, $device] = hashedTerminal(['serial_number' => 'SN-LEGACY', 'webhook_ip_allowlist' => ['203.0.113.7'], 'webhook_token' => null]);

    expect($device->fresh()->hasWebhookToken())->toBeFalse();
    app()->forgetScopedInstances();
    test()->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])
        ->postJson(hashHost($tenant).'/api/v1/devices/webhook/hikvision', [
            'dateTime' => '2026-11-13T09:00:00+03:00',
            'AccessControllerEvent' => ['employeeNoString' => '7301', 'attendanceStatus' => 'checkIn', 'deviceSerialNo' => 'SN-LEGACY'],
        ])->assertStatus(200);
});

it('lets only a tenant admin regenerate, and never another organisation', function () {
    [$tenant, $device] = hashedTerminal();
    regenerate($tenant, $device)->assertOk();

    [$hrTenant, $hrDevice] = hashedTerminal(role: UserRole::HR_ADMIN);
    regenerate($hrTenant, $hrDevice)->assertForbidden();

    [$tenantA, $deviceA, $tokenA] = hashedTerminal();
    $tenantB = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenantB);

    expect(regenerate($tenantB, $deviceA)->status())->toBeIn([403, 404]);
    pushWith($tenantA, $tokenA)->assertStatus(200);
});

it('closes the old address at once when the reason is lost, and when no reason is given', function (?string $reason) {
    [$tenant, $device, $old] = hashedTerminal();

    $new = regenerate($tenant, $device, $reason)->assertOk()->json();

    expect($new['reason'])->toBe('lost')
        ->and($new['webhook_token'])->not->toBe($old)
        ->and($new['webhook_url'])->toContain('?token='.$new['webhook_token'])
        ->and($new['previous_token_expires_at'])->toBeNull();

    pushWith($tenant, $old)->assertStatus(401);
    pushWith($tenant, $new['webhook_token'])->assertStatus(200);
    expect(rawDevice($device)->webhook_token_previous_hash)->toBeNull();
})->with(['lost' => 'lost', 'no reason' => null]);

it('keeps the old address working for 24 hours when the terminal is being moved, then refuses it', function () {
    Carbon::setTestNow(Carbon::parse('2026-11-21 08:00:00', 'UTC'));
    [$tenant, $device, $old] = hashedTerminal();

    $new = regenerate($tenant, $device, 'moving')->assertOk()->json();

    expect($new['previous_token_expires_at'])->not->toBeNull();
    pushWith($tenant, $old)->assertStatus(200);
    pushWith($tenant, $new['webhook_token'])->assertStatus(200);

    Carbon::setTestNow(Carbon::parse('2026-11-22 07:59:00', 'UTC'));
    pushWith($tenant, $old)->assertStatus(200);

    Carbon::setTestNow(Carbon::parse('2026-11-22 08:01:00', 'UTC'));
    pushWith($tenant, $old)->assertStatus(401);
    pushWith($tenant, $new['webhook_token'])->assertStatus(200);
});

it('kills a token still inside its moving grace when the next regeneration is lost', function () {
    [$tenant, $device, $old] = hashedTerminal();

    $second = regenerate($tenant, $device, 'moving')->assertOk()->json('webhook_token');
    pushWith($tenant, $old)->assertStatus(200);

    regenerate($tenant, $device, 'lost')->assertOk();

    pushWith($tenant, $old)->assertStatus(401);
    pushWith($tenant, $second)->assertStatus(401);
});

it('rejects a reason that is neither lost nor moving', function () {
    [$tenant, $device] = hashedTerminal();

    regenerate($tenant, $device, 'whatever')->assertStatus(422);
});

it('records the reason in the audit log', function () {
    [$tenant, $device] = hashedTerminal();

    regenerate($tenant, $device, 'moving')->assertOk();

    $row = DB::table('audit_log')->where('action', 'device.token_regenerated')->latest('id')->first();
    expect($row)->not->toBeNull()
        ->and(json_encode($row))->toContain('moving');
});

it('traces a cut-off address through the head hash, and a retired one through its hash', function () {
    [$tenant, $device, $token] = hashedTerminal();

    pushWith($tenant, substr($token, 0, 40))->assertStatus(401);
    expect(WebhookRejections::last($device)['reason'])->toBe('token_truncated');

    WebhookRejections::forget($device);
    regenerate($tenant, $device, 'lost')->assertOk();
    pushWith($tenant, $token)->assertStatus(401);
    expect(WebhookRejections::last($device)['reason'])->toBe('token_retired');
});

it('does not trace a prefix shorter than sixteen characters', function () {
    [$tenant, $device, $token] = hashedTerminal();

    pushWith($tenant, substr($token, 0, 12))->assertStatus(401);

    expect(WebhookRejections::last($device))->toBeNull();
});

it('returns the token and the address once, on create and on regenerate, and never again', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);
    $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);

    $created = test()->postJson(hashHost($tenant).'/api/v1/devices', [
        'name' => 'Gate',
        'adapter_type' => 'hikvision',
        'serial_number' => 'SN-NEW',
        'branch_public_id' => $branch->public_id,
        'connection_config' => ['ip' => '192.168.1.100', 'port' => 80, 'username' => 'admin', 'password' => 'pass123'],
    ])->assertCreated();

    $token = $created->json('webhook_token');
    $id = $created->json('public_id');
    expect($token)->toMatch('/^[0-9a-f]{64}$/')
        ->and($created->json('webhook_url'))->toContain('?token='.$token);

    foreach (['/api/v1/devices/'.$id, '/api/v1/devices'] as $path) {
        $body = test()->getJson(hashHost($tenant).$path)->assertOk()->getContent();
        expect($body)->not->toContain($token)->not->toContain('"webhook_url"');
    }

    $shown = test()->getJson(hashHost($tenant).'/api/v1/devices/'.$id)->json();
    expect($shown['has_webhook_token'])->toBeTrue()
        ->and($shown['webhook_token_prefix'])->toBe(substr($token, 0, 8))
        ->and($shown)->not->toHaveKey('webhook_token');

    $update = test()->putJson(hashHost($tenant).'/api/v1/devices/'.$id, ['name' => 'Gate 2'])->assertOk()->getContent();
    expect($update)->not->toContain($token);
});

it('puts no hash of a token into the tenant data export', function () {
    $columns = (new ReflectionClass(ExportTenantDataJob::class))->getReflectionConstant('SECRET_COLUMNS')->getValue();

    expect($columns)->toContain('webhook_token', 'webhook_token_hash', 'webhook_token_head_hash', 'webhook_token_prefix', 'webhook_token_previous_hash', 'webhook_token_previous_expires_at');
});

it('authenticates nobody when a token matches two devices', function () {
    [$tenant, $device, $token] = hashedTerminal();
    $other = Device::factory()->hikvision()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $device->branch_id,
        'serial_number' => 'SN-OTHER',
    ]);

    // The unique index refuses a second row with the same current hash.
    expect(fn () => DB::table('devices')->where('id', $other->id)->update(['webhook_token_hash' => hash('sha256', $token)]))
        ->toThrow(QueryException::class);

    // The previous-hash column is a different index, so one device's current
    // hash can equal another's previous one: the lookup finds two and refuses.
    DB::table('devices')->where('id', $other->id)->update([
        'webhook_token_previous_hash' => hash('sha256', $token),
        'webhook_token_previous_expires_at' => now()->addHour(),
    ]);

    pushWith($tenant, $token)->assertStatus(401);
});
