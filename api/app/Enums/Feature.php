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
 * to {@see FeatureGate}. The rest are integrations and public surfaces, which
 * no plan decides (design §6, PR 3).
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

    case WebhooksOutbound = 'webhooks_outbound';
    case WebPush = 'web_push';
    case EmailSignInCodes = 'email_sign_in_codes';
    case AttendanceSelfies = 'attendance_selfies';
    case KioskCheckIn = 'kiosk_check_in';
    case SelfRegistration = 'self_registration';
    case Scim = 'scim';
    case DeviceWebhooksInbound = 'device_webhooks_inbound';
    case PublicNews = 'public_news';
    case PublicVacancies = 'public_vacancies';

    /** The plan feature that also decides this one, or null when no plan does. */
    public function planFeature(): ?PlanFeature
    {
        return PlanFeature::tryFrom($this->value);
    }

    public function kind(): FeatureKind
    {
        return match (true) {
            $this->planFeature() !== null => FeatureKind::Module,
            $this === self::PublicNews, $this === self::PublicVacancies => FeatureKind::PublicSurface,
            default => FeatureKind::Integration,
        };
    }

    /**
     * Whether one organisation can be granted or revoked this feature.
     * Self-registration happens before there is an organisation to ask about,
     * so only its platform state can decide it.
     */
    public function overridable(): bool
    {
        return $this !== self::SelfRegistration;
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
