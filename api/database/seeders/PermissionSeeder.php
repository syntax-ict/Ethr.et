<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Permission;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = $this->permissions();

        foreach ($permissions as $perm) {
            Permission::updateOrCreate(
                ['name' => $perm['name']],
                [
                    'public_id' => (string) Str::ulid(),
                    'module' => $perm['module'],
                    'action' => $perm['action'],
                    'description' => $perm['description'],
                ],
            );
        }

        $permissionIds = Permission::pluck('id', 'name')->all();

        DB::table('role_permissions')->truncate();

        $grants = $this->roleGrants();
        $rows = [];

        foreach ($grants as $role => $permissionNames) {
            foreach ($permissionNames as $name) {
                if (isset($permissionIds[$name])) {
                    $rows[] = [
                        'role' => $role,
                        'permission_id' => $permissionIds[$name],
                    ];
                }
            }
        }

        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('role_permissions')->insert($chunk);
        }

        Permission::clearCache();
    }

    private function permissions(): array
    {
        return [
            // Organization
            ['name' => 'org.viewAny', 'module' => 'org', 'action' => 'viewAny', 'description' => 'View organization structure'],
            ['name' => 'org.view', 'module' => 'org', 'action' => 'view', 'description' => 'View organization unit details'],
            ['name' => 'org.create', 'module' => 'org', 'action' => 'create', 'description' => 'Create organization units'],
            ['name' => 'org.update', 'module' => 'org', 'action' => 'update', 'description' => 'Update organization units'],
            ['name' => 'org.delete', 'module' => 'org', 'action' => 'delete', 'description' => 'Delete organization units'],

            // Attendance
            ['name' => 'attendance.checkIn', 'module' => 'attendance', 'action' => 'checkIn', 'description' => 'Check in/out attendance'],
            ['name' => 'attendance.viewOwn', 'module' => 'attendance', 'action' => 'viewOwn', 'description' => 'View own attendance records'],
            ['name' => 'attendance.viewTeam', 'module' => 'attendance', 'action' => 'viewTeam', 'description' => 'View team attendance records'],
            ['name' => 'attendance.viewAll', 'module' => 'attendance', 'action' => 'viewAll', 'description' => 'View all attendance records'],
            ['name' => 'attendance.view', 'module' => 'attendance', 'action' => 'view', 'description' => 'View attendance records'],
            ['name' => 'attendance.manage', 'module' => 'attendance', 'action' => 'manage', 'description' => 'Manage attendance settings and imports'],

            // Shift
            ['name' => 'shift.viewAny', 'module' => 'shift', 'action' => 'viewAny', 'description' => 'View shifts list'],
            ['name' => 'shift.view', 'module' => 'shift', 'action' => 'view', 'description' => 'View shift details'],
            ['name' => 'shift.create', 'module' => 'shift', 'action' => 'create', 'description' => 'Create shifts'],
            ['name' => 'shift.update', 'module' => 'shift', 'action' => 'update', 'description' => 'Update shifts'],
            ['name' => 'shift.delete', 'module' => 'shift', 'action' => 'delete', 'description' => 'Delete shifts'],

            // Device
            ['name' => 'device.viewAny', 'module' => 'device', 'action' => 'viewAny', 'description' => 'View devices list'],
            ['name' => 'device.view', 'module' => 'device', 'action' => 'view', 'description' => 'View device details'],
            ['name' => 'device.create', 'module' => 'device', 'action' => 'create', 'description' => 'Register devices'],
            ['name' => 'device.update', 'module' => 'device', 'action' => 'update', 'description' => 'Update device configuration'],
            ['name' => 'device.delete', 'module' => 'device', 'action' => 'delete', 'description' => 'Remove devices'],

            // Correction
            ['name' => 'correction.create', 'module' => 'correction', 'action' => 'create', 'description' => 'Submit attendance corrections'],
            ['name' => 'correction.viewOwn', 'module' => 'correction', 'action' => 'viewOwn', 'description' => 'View own correction requests'],
            ['name' => 'correction.viewPending', 'module' => 'correction', 'action' => 'viewPending', 'description' => 'View pending corrections for approval'],
            ['name' => 'correction.viewAll', 'module' => 'correction', 'action' => 'viewAll', 'description' => 'View all correction requests'],
            ['name' => 'correction.approve', 'module' => 'correction', 'action' => 'approve', 'description' => 'Approve or reject corrections'],

            // Holiday
            ['name' => 'holiday.viewAny', 'module' => 'holiday', 'action' => 'viewAny', 'description' => 'View holidays list'],
            ['name' => 'holiday.view', 'module' => 'holiday', 'action' => 'view', 'description' => 'View holiday details'],
            ['name' => 'holiday.create', 'module' => 'holiday', 'action' => 'create', 'description' => 'Create holidays'],
            ['name' => 'holiday.update', 'module' => 'holiday', 'action' => 'update', 'description' => 'Update holidays'],
            ['name' => 'holiday.delete', 'module' => 'holiday', 'action' => 'delete', 'description' => 'Delete holidays'],

            // Payroll
            ['name' => 'payroll.viewAll', 'module' => 'payroll', 'action' => 'viewAll', 'description' => 'View all payroll data'],
            ['name' => 'payroll.process', 'module' => 'payroll', 'action' => 'process', 'description' => 'Process payroll runs'],
            ['name' => 'payroll.approve', 'module' => 'payroll', 'action' => 'approve', 'description' => 'Approve payroll runs'],
            ['name' => 'payroll.void', 'module' => 'payroll', 'action' => 'void', 'description' => 'Void an approved payroll run'],
            ['name' => 'payroll.reprocess', 'module' => 'payroll', 'action' => 'reprocess', 'description' => 'Reprocess a voided payroll run'],
            ['name' => 'payroll.manageLoan', 'module' => 'payroll', 'action' => 'manageLoan', 'description' => 'Manage employee loans'],
            ['name' => 'payroll.viewOwnPayslip', 'module' => 'payroll', 'action' => 'viewOwnPayslip', 'description' => 'View own payslip'],

            // Leave
            ['name' => 'leave.viewTypes', 'module' => 'leave', 'action' => 'viewTypes', 'description' => 'View leave types'],
            ['name' => 'leave.manageTypes', 'module' => 'leave', 'action' => 'manageTypes', 'description' => 'Manage leave types'],
            ['name' => 'leave.request', 'module' => 'leave', 'action' => 'request', 'description' => 'Submit leave requests'],
            ['name' => 'leave.viewTeam', 'module' => 'leave', 'action' => 'viewTeam', 'description' => 'View team leave requests'],
            ['name' => 'leave.viewAll', 'module' => 'leave', 'action' => 'viewAll', 'description' => 'View all leave requests'],
            ['name' => 'leave.approve', 'module' => 'leave', 'action' => 'approve', 'description' => 'Approve or reject leave requests'],
            ['name' => 'leave.adjustBalance', 'module' => 'leave', 'action' => 'adjustBalance', 'description' => 'Manually adjust leave balances'],

            // Employee
            ['name' => 'employee.viewAny', 'module' => 'employee', 'action' => 'viewAny', 'description' => 'View employees list'],
            ['name' => 'employee.view', 'module' => 'employee', 'action' => 'view', 'description' => 'View employee details'],
            ['name' => 'employee.create', 'module' => 'employee', 'action' => 'create', 'description' => 'Create employees'],
            ['name' => 'employee.update', 'module' => 'employee', 'action' => 'update', 'description' => 'Update employee records'],
            ['name' => 'employee.delete', 'module' => 'employee', 'action' => 'delete', 'description' => 'Delete employees'],
            ['name' => 'employee.transition', 'module' => 'employee', 'action' => 'transition', 'description' => 'Transition employee status'],
            ['name' => 'employee.viewFinancial', 'module' => 'employee', 'action' => 'viewFinancial', 'description' => 'View employee financial data'],
            ['name' => 'employee.updateFinancial', 'module' => 'employee', 'action' => 'updateFinancial', 'description' => 'Update employee financial data'],

            // Profile
            ['name' => 'profile.view', 'module' => 'profile', 'action' => 'view', 'description' => 'View own profile'],
            ['name' => 'profile.update', 'module' => 'profile', 'action' => 'update', 'description' => 'Update own profile'],

            // Announcement
            ['name' => 'announcement.manage', 'module' => 'announcement', 'action' => 'manage', 'description' => 'Manage announcements'],

            // Dashboard
            ['name' => 'dashboard.executive', 'module' => 'dashboard', 'action' => 'executive', 'description' => 'View executive dashboard'],

            // Report
            ['name' => 'report.generate', 'module' => 'report', 'action' => 'generate', 'description' => 'Generate reports'],

            // API Key
            ['name' => 'apikey.manage', 'module' => 'apikey', 'action' => 'manage', 'description' => 'Manage API keys'],

            // Webhook
            ['name' => 'webhook.manage', 'module' => 'webhook', 'action' => 'manage', 'description' => 'Manage webhooks'],

            // Admin
            ['name' => 'admin.manage', 'module' => 'admin', 'action' => 'manage', 'description' => 'Platform administration'],

            // Billing
            ['name' => 'billing.manage', 'module' => 'billing', 'action' => 'manage', 'description' => 'Manage billing and subscriptions'],

            // Settings
            ['name' => 'settings.manage', 'module' => 'settings', 'action' => 'manage', 'description' => 'Manage tenant settings'],
        ];
    }

    /**
     * Maps each role to its granted permissions.
     * Replicates the exact behavior of the current Gate::define isAtLeast() logic.
     *
     * SUPER_ADMIN is omitted — it gets an implicit bypass in User::hasPermission().
     */
    private function roleGrants(): array
    {
        // Permissions granted to everyone (isAtLeast not required / fn() => true)
        $everyone = [
            'org.viewAny', 'org.view',
            'attendance.checkIn', 'attendance.viewOwn', 'attendance.view',
            'shift.viewAny', 'shift.view',
            'correction.create', 'correction.viewOwn',
            'holiday.viewAny', 'holiday.view',
            'payroll.viewOwnPayslip',
            'leave.viewTypes', 'leave.request',
            'profile.view', 'profile.update',
        ];

        // isAtLeast(SUPERVISOR) — level 30+
        $supervisor = [
            'attendance.viewTeam',
            'correction.viewPending', 'correction.approve',
            'leave.viewTeam', 'leave.approve',
            'employee.viewAny', 'employee.view',
        ];

        // isAtLeast(HR_ADMIN) — level 70+ (HR_ADMIN & FINANCE_ADMIN both at 70)
        $hrAdmin = [
            'org.create', 'org.update',
            'attendance.viewAll', 'attendance.manage',
            'shift.create', 'shift.update',
            'device.viewAny', 'device.view',
            'correction.viewAll',
            'holiday.create', 'holiday.update',
            'leave.manageTypes', 'leave.viewAll', 'leave.adjustBalance',
            'employee.create', 'employee.update', 'employee.transition',
            'employee.viewFinancial', 'employee.updateFinancial',
            'announcement.manage',
            'report.generate',
        ];

        // isAtLeast(FINANCE_ADMIN) — level 70+ (same level as HR_ADMIN)
        $financeAdmin = [
            'payroll.viewAll', 'payroll.process', 'payroll.manageLoan',
        ];

        // isAtLeast(TENANT_ADMIN) — level 90+
        $tenantAdmin = [
            'org.delete',
            'shift.delete',
            'device.create', 'device.update', 'device.delete',
            'holiday.delete',
            'payroll.approve',
            'payroll.void',
            'payroll.reprocess',
            'employee.delete',
            'dashboard.executive',
            'apikey.manage',
            'webhook.manage',
            'billing.manage',
            'settings.manage',
        ];

        // Build cumulative grants per role (replicating isAtLeast hierarchy)
        return [
            'employee' => $everyone,
            'supervisor' => array_merge($everyone, $supervisor),
            'dept_admin' => array_merge($everyone, $supervisor),
            'finance_admin' => array_merge($everyone, $supervisor, $hrAdmin, $financeAdmin),
            'hr_admin' => array_merge($everyone, $supervisor, $hrAdmin, $financeAdmin),
            'tenant_admin' => array_merge($everyone, $supervisor, $hrAdmin, $financeAdmin, $tenantAdmin),
        ];
    }
}
