<?php

declare(strict_types=1);

namespace App\Enums;

use App\Services\Features\FeatureGate;

/**
 * Every feature the platform admin can switch without a deploy.
 *
 * The catalogue lives in code and the state in the database
 * (`platform_feature_states`, `tenant_feature_overrides`), because a switch
 * works only where code checks it: a key with no check would be a control
 * that does nothing. A feature enters this list in the same change that adds
 * its checks. See docs/FEATURE-CONTROL-CENTER-DESIGN.md §4.
 *
 * The first entries are the plan features, under the same strings, so
 * `plans.features` needs no data migration and the plan gate becomes one input
 * to {@see FeatureGate}.
 */
enum Feature: string
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
    case CustomDomain = 'custom_domain';

    /**
     * The plan feature that also decides this one. Every feature is a plan
     * feature for now; the first that is not makes this nullable.
     */
    public function planFeature(): PlanFeature
    {
        return PlanFeature::from($this->value);
    }

    /**
     * The platform state when no row says otherwise. Enabled for every feature
     * that existed before the control center, so shipping it changed nothing.
     */
    public function defaultState(): FeatureState
    {
        return FeatureState::Enabled;
    }

    public static function fromPlanFeature(PlanFeature $feature): self
    {
        return self::from($feature->value);
    }
}
