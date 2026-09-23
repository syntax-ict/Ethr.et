<?php

declare(strict_types=1);

use App\Models\Branch;
use App\Models\Device;
use App\Models\Employee;
use App\Models\Tenant;
use Illuminate\Database\QueryException;

/**
 * The two device-webhook lookups that drop the tenant scope, and the invariants
 * that keep them from reaching another tenant's device.
 *
 * `DeviceController::resolveWebhookDevice()` cannot use the tenant scope: a
 * biometric device posts to the webhook with no session, no user and no
 * resolvable tenant. It therefore looks a device up two ways, both with
 * `withoutGlobalScope('tenant')`:
 *
 *   1. by `webhook_token` — the token both selects and authenticates;
 *   2. by `(serial_number, adapter_type)` — the serial only *selects*; a
 *      candidate that has a token is rejected, and a token-less candidate must
 *      come from an allowlisted source IP.
 *
 * `docs/audit/BASELINE.md` §11d recorded both as safe for reasons nothing
 * enforced. Neither column carried a unique index, so (1) was safe only because
 * `Device::generateWebhookToken()` happens to produce 256 bits of CSPRNG
 * output, and (2) was safe only because `Device::webhookIpAllowed()` happens to
 * be fail-closed. Both are one careless edit from being cross-tenant reads.
 *
 * (1) is now enforced by a unique index — see
 * `2026_09_23_000001_add_unique_index_to_device_webhook_token`. The tests below
 * pin that index, the generator's shape, and the fail-closed default that (2)
 * rests on, at the level where a change would be made.
 */

// ── The webhook_token lookup: uniqueness is now the database's job ──

test('the database refuses two devices sharing a webhook token', function () {
    $tenantA = createTenant();
    $tenantB = Tenant::factory()->create();

    Device::factory()->zkteco()->create([
        'tenant_id' => $tenantA->id,
        'branch_id' => Branch::factory()->create(['tenant_id' => $tenantA->id])->id,
        'webhook_token' => 'collision',
    ]);

    // The token lookup in resolveWebhookDevice() drops the tenant scope, so a
    // second device holding the same token would be selectable by the first
    // tenant's credential. The index makes that unrepresentable.
    expect(fn () => Device::factory()->zkteco()->create([
        'tenant_id' => $tenantB->id,
        'branch_id' => Branch::factory()->create(['tenant_id' => $tenantB->id])->id,
        'webhook_token' => 'collision',
    ]))->toThrow(QueryException::class);
});

test('the unique index still allows any number of token-less devices', function () {
    $tenant = createTenant();
    $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);

    // Token-less is a supported mode — those devices authenticate by IP
    // allowlist instead, and DemoTenantSeeder creates them this way. A unique
    // index permits unlimited NULLs on both SQLite and MariaDB; this pins that
    // the column was not also made NOT NULL.
    Device::factory()->count(3)->zkteco()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'webhook_token' => null,
    ]);

    expect(Device::where('tenant_id', $tenant->id)->whereNull('webhook_token')->count())->toBe(3);
});

test('generated webhook tokens are 64 hex characters and do not repeat', function () {
    // The index catches a collision; it cannot catch a weak generator. This
    // pins the entropy half of the invariant — a shortened or derived token
    // fails here rather than silently narrowing the keyspace of a lookup that
    // has no tenant predicate.
    $token = Device::generateWebhookToken();

    expect($token)->toMatch('/^[0-9a-f]{64}$/');
    expect(Device::generateWebhookToken())->not->toBe($token);
});

// ── The serial lookup: the fail-closed IP allowlist is what holds it ──

test('webhookIpAllowed is fail-closed on every absent or malformed input', function (mixed $allowlist, ?string $ip) {
    $device = new Device(['webhook_ip_allowlist' => $allowlist]);

    expect($device->webhookIpAllowed($ip))->toBeFalse();
})->with([
    'no allowlist, known ip' => [null, '203.0.113.5'],
    'empty allowlist, known ip' => [[], '203.0.113.5'],
    'allowlist present, null ip' => [['203.0.113.5'], null],
    'allowlist present, empty ip' => [['203.0.113.5'], ''],
    'allowlist present, other ip' => [['203.0.113.5'], '198.51.100.9'],
    'blank entries only' => [['', '  '], '203.0.113.5'],
    'nothing at all' => [null, null],
]);

test('webhookIpAllowed admits an exact address and a CIDR block', function () {
    expect((new Device(['webhook_ip_allowlist' => ['203.0.113.5']]))->webhookIpAllowed('203.0.113.5'))->toBeTrue();
    expect((new Device(['webhook_ip_allowlist' => ['203.0.113.0/24']]))->webhookIpAllowed('203.0.113.77'))->toBeTrue();
    expect((new Device(['webhook_ip_allowlist' => ['203.0.113.0/24']]))->webhookIpAllowed('198.51.100.77'))->toBeFalse();
});

test('a serial shared by two tenants cannot write attendance into the wrong one', function () {
    // Serials are printed on the hardware and carry no unique index, so two
    // tenants CAN register the same one. Which row `->first()` returns is not
    // defined — so the invariant under test is not "it picks the right device"
    // but "the wrong tenant gets nothing either way": if it selects tenant A's
    // device, the source IP is not on A's allowlist and the call is rejected;
    // if it selects tenant B's, the record is filed against B.
    $tenantA = createTenant();
    $branchA = Branch::factory()->create(['tenant_id' => $tenantA->id]);
    Device::factory()->zkteco()->create([
        'tenant_id' => $tenantA->id,
        'branch_id' => $branchA->id,
        'serial_number' => 'SHARED-SN',
        'webhook_token' => null,
        'webhook_ip_allowlist' => ['203.0.113.5'],
    ]);
    Employee::factory()->create(['tenant_id' => $tenantA->id, 'employee_code' => 'EMP-A']);

    $tenantB = Tenant::factory()->create();
    $branchB = Branch::factory()->create(['tenant_id' => $tenantB->id]);
    Device::factory()->zkteco()->create([
        'tenant_id' => $tenantB->id,
        'branch_id' => $branchB->id,
        'serial_number' => 'SHARED-SN',
        'webhook_token' => null,
        'webhook_ip_allowlist' => ['198.51.100.9'],
    ]);
    Employee::factory()->create(['tenant_id' => $tenantB->id, 'employee_code' => 'EMP-B']);

    // Posted from tenant B's allowlisted IP, at tenant A's host, with the
    // serial both of them share.
    test()->withServerVariables(['REMOTE_ADDR' => '198.51.100.9'])
        ->postJson("http://{$tenantA->subdomain}.ethr.test/api/v1/devices/webhook/zkteco", [
            'sn' => 'SHARED-SN',
            'records' => [['pin' => 'EMP-B', 'timestamp' => now()->toIso8601String(), 'punch' => 0]],
        ]);

    $this->assertDatabaseMissing('attendance_records', ['tenant_id' => $tenantA->id]);
});
