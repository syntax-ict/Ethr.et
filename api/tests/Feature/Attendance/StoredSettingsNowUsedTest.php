<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\Device;
use App\Models\Employee;
use App\Models\Shift;
use App\Services\CurrentTenant;

/**
 * Two settings the attendance QA found stored and shown but used by nothing
 * (docs/audit/QA-ATTENDANCE-PRODUCTION-2026-10-12.md, H7 and H10).
 */
/*
 * The organisation-wide grace setting was retired on 2026-10-24: grace is a
 * property of a shift, and only the shift's value ever rated a punch. A new
 * shift now starts from the default shift's rules instead.
 */
it('gives a new shift the grace, break and days of the default shift when it sets none of its own', function () {
    $tenant = createTenant();
    Shift::factory()->default()->create([
        'tenant_id' => $tenant->id,
        'grace_minutes' => 7,
        'break_minutes' => 45,
        'working_days' => '1,2,3,4,5,6',
    ]);
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);
    $url = "http://{$tenant->subdomain}.ethr.test/api/v1/shifts";

    test()->postJson($url, ['name' => 'Morning', 'start_time' => '08:00', 'end_time' => '17:00'])
        ->assertCreated()
        ->assertJsonPath('grace_minutes', 7)
        ->assertJsonPath('break_minutes', 45)
        ->assertJsonPath('working_days', '1,2,3,4,5,6');

    // The shift's own value still wins.
    test()->postJson($url, ['name' => 'Night', 'start_time' => '22:00', 'end_time' => '06:00', 'grace_minutes' => 20])
        ->assertCreated()
        ->assertJsonPath('grace_minutes', 20);
});

it('lets an admin set the webhook allowlist, and a token-less device is then accepted from it', function () {
    $tenant = createTenant();
    $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
    Employee::factory()->create(['tenant_id' => $tenant->id, 'employee_code' => 'EMP-IP']);
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    $id = test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/devices", [
        'name' => 'Gate terminal', 'adapter_type' => 'zkteco', 'branch_public_id' => $branch->public_id,
        'serial_number' => 'ZK-IP-1', 'auto_sync' => false,
        'connection_config' => ['ip' => '192.0.2.10', 'port' => 4370],
        'webhook_ip_allowlist' => ['203.0.113.7'],
    ])->assertCreated()
        ->assertJsonPath('webhook_ip_allowlist', ['203.0.113.7'])
        ->json('public_id');

    test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/devices", [
        'name' => 'Bad', 'adapter_type' => 'zkteco', 'branch_public_id' => $branch->public_id,
        'connection_config' => ['ip' => '192.0.2.11', 'port' => 4370],
        'webhook_ip_allowlist' => ['not-an-ip'],
    ])->assertUnprocessable()->assertJsonValidationErrors('webhook_ip_allowlist.0');

    Device::withoutGlobalScopes()->where('public_id', $id)->update(['webhook_token' => null]);
    app(CurrentTenant::class)->forget();
    auth()->forgetGuards();

    test()->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])
        ->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/devices/webhook/zkteco", [
            'sn' => 'ZK-IP-1',
            'records' => [['pin' => 'EMP-IP', 'timestamp' => now()->toIso8601String(), 'punch' => 0]],
        ])->assertOk()->assertJsonPath('status', 'processed');
});
