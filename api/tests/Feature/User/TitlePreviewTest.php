<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\CustomRole;
use App\Models\Employee;
use App\Models\Position;
use App\Models\Tenant;
use App\Models\User;

// Before an account is switched to follow its job title, the Users list says
// what that would give it (`title_preview`) and whether the signed-in person
// could give it. `title_role` stays null until the account follows.

function previewPosition(Tenant $tenant, ?string $access, string $title = 'Clerk'): Position
{
    return Position::factory()->create(['tenant_id' => $tenant->id, 'title' => $title, 'access_role' => $access]);
}

function previewHolder(Tenant $tenant, ?Position $position, UserRole $role = UserRole::EMPLOYEE, bool $follows = false): User
{
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'position_id' => $position?->id]);

    return User::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'role' => $role,
        'role_follows_position' => $follows,
    ]);
}

function listedAccount(User $account): array
{
    $rows = test()->getJson('/api/v1/users?per_page=100')->assertOk()->json('data');

    return collect($rows)->firstWhere('public_id', $account->public_id);
}

describe('the preview on the users list', function () {
    it('names the role and the title for an account that does not follow, and leaves title_role null', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);
        $holder = previewHolder($tenant, previewPosition($tenant, 'finance_admin', 'Accountant'));

        $row = listedAccount($holder);

        expect($row['title_role'])->toBeNull()
            ->and($row['role_follows_position'])->toBeFalse()
            ->and($row['title_preview'])->toBe(['role' => 'finance_admin', 'position' => 'Accountant', 'applies' => true]);
    });

    it('previews employee with no position name when the employee has no job title', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);
        $holder = previewHolder($tenant, null, UserRole::HR_ADMIN);

        expect(listedAccount($holder)['title_preview'])
            ->toBe(['role' => 'employee', 'position' => null, 'applies' => true]);
    });

    it('is null for the organisation owner, a custom-role account and an account with no employee', function () {
        $tenant = createTenant();
        $admin = actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);
        $position = previewPosition($tenant, 'hr_admin');

        $owner = previewHolder($tenant, $position, UserRole::TENANT_ADMIN);
        $tenant->forceFill(['owner_user_id' => $owner->id])->save();

        $custom = previewHolder($tenant, $position);
        $role = CustomRole::create(['tenant_id' => $tenant->id, 'name' => 'Auditor', 'org_scope' => 'all']);
        $custom->update(['custom_role_id' => $role->id]);

        $loginOnly = User::factory()->create(['tenant_id' => $tenant->id, 'role' => UserRole::EMPLOYEE]);

        expect(listedAccount($owner)['title_preview'])->toBeNull()
            ->and(listedAccount($custom)['title_preview'])->toBeNull()
            ->and(listedAccount($loginOnly)['title_preview'])->toBeNull();
    });

    it('says applies is false when the viewer could not give that role by hand', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
        $accountant = previewPosition($tenant, 'finance_admin', 'Accountant');
        $clerk = previewPosition($tenant, null);

        $toFinance = previewHolder($tenant, $accountant);
        $peer = previewHolder($tenant, $clerk, UserRole::HR_ADMIN);
        $toEmployee = previewHolder($tenant, $clerk, UserRole::SUPERVISOR);
        $above = previewHolder($tenant, $clerk, UserRole::TENANT_ADMIN);

        expect(listedAccount($toFinance)['title_preview']['applies'])->toBeFalse()
            ->and(listedAccount($peer)['title_preview']['applies'])->toBeFalse()
            ->and(listedAccount($above)['title_preview'])->toBe(['role' => 'employee', 'position' => 'Clerk', 'applies' => false])
            ->and(listedAccount($toEmployee)['title_preview']['applies'])->toBeTrue();
    });

    it('says applies is true when the role already equals the preview', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
        $same = previewHolder($tenant, previewPosition($tenant, 'hr_admin'), UserRole::HR_ADMIN);

        expect(listedAccount($same)['title_preview']['applies'])->toBeTrue();
    });

    it('keeps title_role for an account that follows', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);
        $holder = previewHolder($tenant, previewPosition($tenant, 'supervisor'), follows: true);

        $row = listedAccount($holder);

        expect($row['title_role'])->toBe('supervisor')
            ->and($row['title_preview']['role'])->toBe('supervisor');
    });
});
