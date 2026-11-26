<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Tenant;
use App\Models\User;
use App\Services\CurrentTenant;
use App\Support\TenantRecipients;

/**
 * One lookup behind four queued listeners, which run with no tenant resolved.
 * What it must return is the organisation's active holders of a role, and nobody
 * else: not a suspended user, not a removed one, not another organisation's.
 */
it('returns only the organisation\'s active, undeleted holders of the roles', function () {
    $tenant = Tenant::factory()->create(['subdomain' => 'recipients-a']);
    $other = Tenant::factory()->create(['subdomain' => 'recipients-b']);

    $make = fn (Tenant $t, UserRole $role, array $extra = []) => User::factory()->create([
        'tenant_id' => $t->id,
        'role' => $role->value,
    ] + $extra);

    $admin = $make($tenant, UserRole::TENANT_ADMIN);
    $finance = $make($tenant, UserRole::FINANCE_ADMIN);
    $make($tenant, UserRole::EMPLOYEE);
    $make($tenant, UserRole::HR_ADMIN, ['status' => 'suspended']);
    $make($tenant, UserRole::HR_ADMIN)->delete();
    $make($other, UserRole::TENANT_ADMIN);

    // As on a queue worker: nothing resolved.
    app(CurrentTenant::class)->forget();

    $found = TenantRecipients::withRoles($tenant->id, [UserRole::TENANT_ADMIN, UserRole::HR_ADMIN, UserRole::FINANCE_ADMIN]);

    expect($found->pluck('id')->sort()->values()->all())->toBe(collect([$admin->id, $finance->id])->sort()->values()->all());
});
