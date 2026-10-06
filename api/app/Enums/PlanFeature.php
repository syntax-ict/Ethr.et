<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The feature keys stored in `Plan.features`.
 *
 * These strings already existed as free-form array entries in `PlanSeeder` and
 * were read by nothing except the pricing page — so a Starter tenant could use
 * payroll, webhooks and the audit log despite paying for none of them. Naming
 * them here is what makes the gate checkable: a typo in a route middleware
 * argument is now a fatal `ValueError` at boot rather than a silently
 * permissive check.
 *
 * The values are load-bearing and must match `PlanSeeder` exactly. They are
 * also persisted in the `plans.features` JSON column of every existing
 * deployment, so renaming one is a data migration, not a rename.
 */
enum PlanFeature: string
{
    case Attendance = 'attendance';
    case Leave = 'leave';
    case EmployeeManagement = 'employee_management';
    case Payroll = 'payroll';
    case Reports = 'reports';
    case Notifications = 'notifications';
    case ApiAccess = 'api_access';
    case Webhooks = 'webhooks';
    case CustomReports = 'custom_reports';
    case AuditLog = 'audit_log';

    /**
     * The translation key describing what a tenant must upgrade to reach.
     */
    public function upgradeMessageKey(): string
    {
        return 'billing.feature_not_in_plan';
    }
}
