<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\CustomRole;
use App\Models\Tenant;
use App\Models\User;
use App\Services\CurrentTenant;

// The owner's direction for the console (2026-11-04): beside the owner, the
// organisation's administrators (role and custom role, MFA, account status,
// last sign-in) and what was done with that power, with who did it.

it('lists the organisation\'s administrators and its recent privileged actions', function () {
    $tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($tenant);
    $owner = User::factory()->create(['tenant_id' => $tenant->id, 'role' => UserRole::TENANT_ADMIN, 'display_name' => 'Selam Bekele', 'mfa_enabled' => true, 'last_login_at' => now()->subDay()]);
    $tenant->update(['owner_user_id' => $owner->id]);
    $role = CustomRole::query()->create(['tenant_id' => $tenant->id, 'name' => 'Payroll checker', 'is_active' => true]);
    $custom = User::factory()->create(['tenant_id' => $tenant->id, 'role' => UserRole::EMPLOYEE, 'custom_role_id' => $role->id, 'display_name' => 'Hana Tadesse']);
    User::factory()->create(['tenant_id' => $tenant->id, 'role' => UserRole::EMPLOYEE, 'display_name' => 'Not an admin']);
    test()->actingAs($owner);
    AuditLog::record('user.role_changed', $custom);
    AuditLog::record('leave.requested', $custom);
    app(CurrentTenant::class)->forget();
    app('auth')->forgetGuards();

    $home = createTenant();
    test()->actingAs(createUser(['role' => UserRole::SUPER_ADMIN, 'mfa_enabled' => true], $home));

    $detail = test()->getJson("http://{$home->subdomain}.ethr.test/api/v1/admin/tenants/{$tenant->public_id}")->assertOk();

    $admins = collect($detail->json('administrators'))->keyBy('name');
    expect($admins->keys()->all())->toEqualCanonicalizing(['Selam Bekele', 'Hana Tadesse'])
        ->and($admins['Selam Bekele']['is_owner'])->toBeTrue()
        ->and($admins['Selam Bekele']['mfa_enabled'])->toBeTrue()
        ->and($admins['Hana Tadesse']['custom_role'])->toBe('Payroll checker')
        ->and($admins['Hana Tadesse']['is_owner'])->toBeFalse();

    $actions = collect($detail->json('privileged_actions'));
    expect($actions->pluck('action')->all())->toContain('user.role_changed')
        ->not->toContain('leave.requested')
        ->and($actions->firstWhere('action', 'user.role_changed')['actor'])->toBe('Selam Bekele');
});
