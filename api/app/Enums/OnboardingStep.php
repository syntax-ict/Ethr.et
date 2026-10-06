<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Canonical onboarding steps.
 *
 * The numeric values are the wire format used by `PUT /onboarding/progress/{step}`
 * and stored in `onboarding_progress.completed_steps`, so they must stay stable.
 * See ONBOARDING_V2.md (decision D1) for how the eleven-step brief collapses onto
 * these seven.
 */
enum OnboardingStep: int
{
    case WELCOME_COMPANY = 1;
    case INDUSTRY = 2;
    case SMART_CONFIGURATION = 3;
    case WORKFORCE_MIGRATION = 4;
    case ACCESS_IDENTITY = 5;
    case INVITE_TEAM = 6;
    case READINESS_GO_LIVE = 7;

    public function key(): string
    {
        return match ($this) {
            self::WELCOME_COMPANY => 'welcome_company',
            self::INDUSTRY => 'industry',
            self::SMART_CONFIGURATION => 'smart_configuration',
            self::WORKFORCE_MIGRATION => 'workforce_migration',
            self::ACCESS_IDENTITY => 'access_identity',
            self::INVITE_TEAM => 'invite_team',
            self::READINESS_GO_LIVE => 'readiness_go_live',
        };
    }

    public static function first(): self
    {
        return self::WELCOME_COMPANY;
    }

    public static function last(): self
    {
        return self::READINESS_GO_LIVE;
    }
}
