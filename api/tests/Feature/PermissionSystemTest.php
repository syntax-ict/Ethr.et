<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Permission;
use Illuminate\Support\Facades\DB;

// ──────────────────────── hasPermission() unit behavior ────────────────────────

describe('hasPermission', function () {
    it('grants super_admin all permissions implicitly', function () {
        $tenant = createTenant();
        $user = createUser(['role' => UserRole::SUPER_ADMIN], $tenant);

        expect($user->hasPermission('employee.viewAny'))->toBeTrue()
            ->and($user->hasPermission('admin.manage'))->toBeTrue()
            ->and($user->hasPermission('nonexistent.ability'))->toBeTrue();
    });

    it('grants tenant_admin seeded permissions', function () {
        $tenant = createTenant();
        $user = createUser(['role' => UserRole::TENANT_ADMIN], $tenant);

        expect($user->hasPermission('employee.viewAny'))->toBeTrue()
            ->and($user->hasPermission('employee.delete'))->toBeTrue()
            ->and($user->hasPermission('payroll.approve'))->toBeTrue()
            ->and($user->hasPermission('settings.manage'))->toBeTrue()
            ->and($user->hasPermission('admin.manage'))->toBeFalse();
    });

    it('grants hr_admin HR and finance permissions at level 70', function () {
        $tenant = createTenant();
        $user = createUser(['role' => UserRole::HR_ADMIN], $tenant);

        expect($user->hasPermission('employee.create'))->toBeTrue()
            ->and($user->hasPermission('employee.update'))->toBeTrue()
            ->and($user->hasPermission('report.generate'))->toBeTrue()
            ->and($user->hasPermission('payroll.viewAll'))->toBeTrue()
            ->and($user->hasPermission('payroll.process'))->toBeTrue()
            ->and($user->hasPermission('employee.delete'))->toBeFalse()
            ->and($user->hasPermission('settings.manage'))->toBeFalse();
    });

    it('grants finance_admin HR and finance permissions at level 70', function () {
        $tenant = createTenant();
        $user = createUser(['role' => UserRole::FINANCE_ADMIN], $tenant);

        expect($user->hasPermission('payroll.viewAll'))->toBeTrue()
            ->and($user->hasPermission('payroll.process'))->toBeTrue()
            ->and($user->hasPermission('employee.create'))->toBeTrue()
            ->and($user->hasPermission('report.generate'))->toBeTrue()
            ->and($user->hasPermission('payroll.approve'))->toBeFalse()
            ->and($user->hasPermission('settings.manage'))->toBeFalse();
    });

    it('grants supervisor team-level permissions only', function () {
        $tenant = createTenant();
        $user = createUser(['role' => UserRole::SUPERVISOR], $tenant);

        expect($user->hasPermission('attendance.viewTeam'))->toBeTrue()
            ->and($user->hasPermission('leave.approve'))->toBeTrue()
            ->and($user->hasPermission('employee.viewAny'))->toBeTrue()
            ->and($user->hasPermission('employee.create'))->toBeFalse()
            ->and($user->hasPermission('payroll.viewAll'))->toBeFalse();
    });

    it('grants employee only self-service permissions', function () {
        $tenant = createTenant();
        $user = createUser(['role' => UserRole::EMPLOYEE], $tenant);

        expect($user->hasPermission('attendance.checkIn'))->toBeTrue()
            ->and($user->hasPermission('attendance.viewOwn'))->toBeTrue()
            ->and($user->hasPermission('leave.request'))->toBeTrue()
            ->and($user->hasPermission('profile.view'))->toBeTrue()
            ->and($user->hasPermission('payroll.viewOwnPayslip'))->toBeTrue()
            ->and($user->hasPermission('employee.viewAny'))->toBeFalse()
            ->and($user->hasPermission('attendance.viewTeam'))->toBeFalse();
    });

    it('denies unknown permissions for non-super-admin roles', function () {
        $tenant = createTenant();
        $user = createUser(['role' => UserRole::HR_ADMIN], $tenant);

        expect($user->hasPermission('fake.permission'))->toBeFalse();
    });
});

// ──────────────────────── Gate integration ────────────────────────

describe('Gate authorization via permissions', function () {
    it('authorizes hr_admin to create employees via Gate', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

        $response = $this->postJson('/api/v1/employees', [
            'name' => 'Test Employee',
        ]);

        expect($response->status())->not->toBe(403);
    });

    it('denies employee from listing employees via Gate', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::EMPLOYEE], $tenant);

        $this->getJson('/api/v1/employees')->assertForbidden();
    });

    it('allows supervisor to list employees via Gate', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::SUPERVISOR], $tenant);

        $this->getJson('/api/v1/employees')->assertOk();
    });
});

// ──────────────────────── Permission model ────────────────────────

describe('Permission model', function () {
    it('identifies known abilities', function () {
        Permission::clearCache();

        expect(Permission::isKnownAbility('employee.viewAny'))->toBeTrue()
            ->and(Permission::isKnownAbility('nonexistent.ability'))->toBeFalse();
    });

    it('returns correct permissions for a role', function () {
        Permission::clearCache();
        $permissions = Permission::permissionsForRole('employee');

        expect($permissions)->toContain('attendance.checkIn')
            ->and($permissions)->toContain('profile.view')
            ->and($permissions)->not->toContain('employee.create');
    });

    it('caches role permissions', function () {
        Permission::clearCache();

        $first = Permission::permissionsForRole('supervisor');
        $second = Permission::permissionsForRole('supervisor');

        expect($first)->toBe($second);
    });

    it('clears cache correctly', function () {
        Permission::permissionsForRole('employee');
        Permission::clearCache('employee');

        expect(cache()->has('role_permissions:employee'))->toBeFalse();
    });
});

// ──────────────────────── Seeder completeness ────────────────────────

describe('PermissionSeeder completeness', function () {
    it('seeds all 61 permissions', function () {
        expect(Permission::count())->toBe(61);
    });

    it('seeds permissions for all non-super-admin roles', function () {
        $roles = ['employee', 'supervisor', 'dept_admin', 'finance_admin', 'hr_admin', 'tenant_admin'];

        foreach ($roles as $role) {
            $count = DB::table('role_permissions')
                ->where('role', $role)
                ->count();

            expect($count)->toBeGreaterThan(0, "Role '{$role}' has no permissions");
        }
    });

    it('gives tenant_admin more permissions than hr_admin', function () {
        $taCount = count(Permission::permissionsForRole('tenant_admin'));
        $hrCount = count(Permission::permissionsForRole('hr_admin'));

        expect($taCount)->toBeGreaterThan($hrCount);
    });

    it('gives hr_admin more permissions than supervisor', function () {
        $hrCount = count(Permission::permissionsForRole('hr_admin'));
        $supCount = count(Permission::permissionsForRole('supervisor'));

        expect($hrCount)->toBeGreaterThan($supCount);
    });

    it('gives supervisor more permissions than employee', function () {
        $supCount = count(Permission::permissionsForRole('supervisor'));
        $empCount = count(Permission::permissionsForRole('employee'));

        expect($supCount)->toBeGreaterThan($empCount);
    });

    it('does not seed super_admin in role_permissions (implicit bypass)', function () {
        $count = DB::table('role_permissions')
            ->where('role', 'super_admin')
            ->count();

        expect($count)->toBe(0);
    });
});
