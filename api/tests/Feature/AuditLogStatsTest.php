<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Tenant;
use App\Services\CurrentTenant;
use Carbon\Carbon;

/**
 * GET /audit-logs/stats: entries per day and per action for the charts above
 * the audit trail, with days counted in the organisation's zone (charts
 * upgrade, 2026-11-04).
 */
function auditStatsUrl(string $query = ''): string
{
    $tenant = app(CurrentTenant::class)->get();

    return "http://{$tenant->subdomain}.ethr.test/api/v1/audit-logs/stats{$query}";
}

function auditEntry(Tenant $tenant, string $action, string $utc): void
{
    AuditLog::query()->create([
        'tenant_id' => $tenant->id,
        'action' => $action,
        'payload' => [],
        'created_at' => Carbon::parse($utc, 'UTC'),
    ]);
}

test('counts entries per day in the organisation zone and per action', function () {
    $tenant = createTenant(['timezone' => 'Africa/Addis_Ababa']);
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    // 21:30 UTC on 2 November is 00:30 on 3 November in Addis.
    auditEntry($tenant, 'employee.created', '2026-11-02 05:00:00');
    auditEntry($tenant, 'employee.updated', '2026-11-02 21:30:00');
    auditEntry($tenant, 'employee.updated', '2026-11-03 09:00:00');
    auditEntry($tenant, 'payroll.approved', '2026-11-04 09:00:00');
    // Outside the range.
    auditEntry($tenant, 'employee.deleted', '2026-10-30 09:00:00');

    $response = test()->getJson(auditStatsUrl('?filter[from]=2026-11-01&filter[to]=2026-11-05'));

    $response->assertOk()
        ->assertJsonPath('from', '2026-11-01')
        ->assertJsonPath('to', '2026-11-05');

    expect($response->json('by_day'))->toBe([
        ['date' => '2026-11-02', 'count' => 1],
        ['date' => '2026-11-03', 'count' => 2],
        ['date' => '2026-11-04', 'count' => 1],
    ])->and($response->json('by_action'))->toBe([
        ['action' => 'employee.updated', 'count' => 2],
        ['action' => 'employee.created', 'count' => 1],
        ['action' => 'payroll.approved', 'count' => 1],
    ]);
});

test('an action filter narrows both counts', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    auditEntry($tenant, 'employee.created', '2026-11-02 05:00:00');
    auditEntry($tenant, 'payroll.approved', '2026-11-02 06:00:00');

    $response = test()->getJson(auditStatsUrl('?filter[from]=2026-11-01&filter[to]=2026-11-05&filter[action]=payroll'));

    $response->assertOk();
    expect($response->json('by_day'))->toBe([['date' => '2026-11-02', 'count' => 1]])
        ->and($response->json('by_action'))->toBe([['action' => 'payroll.approved', 'count' => 1]]);
});

test('another organisation entries are not counted', function () {
    $other = createTenant();
    auditEntry($other, 'employee.created', '2026-11-02 05:00:00');

    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    test()->getJson(auditStatsUrl('?filter[from]=2026-11-01&filter[to]=2026-11-05'))
        ->assertOk()
        ->assertJsonPath('by_day', [])
        ->assertJsonPath('by_action', []);
});

test('a range over three months or out of order is refused', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    test()->getJson(auditStatsUrl('?filter[from]=2026-01-01&filter[to]=2026-06-01'))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['filter.to']);

    test()->getJson(auditStatsUrl('?filter[from]=2026-11-05&filter[to]=2026-11-01'))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['filter.to']);
});

test('an employee is refused', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::EMPLOYEE], $tenant);

    test()->getJson(auditStatsUrl())->assertStatus(403);
});
