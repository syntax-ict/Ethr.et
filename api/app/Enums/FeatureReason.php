<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Why a feature is on or off for an organisation. Sent to the screen as is,
 * so it can tell "upgrade your plan" from "temporarily unavailable"; the
 * platform admin's own written reason never leaves the console.
 */
enum FeatureReason: string
{
    case Enabled = 'enabled';
    case Granted = 'granted';
    case PlatformDisabled = 'platform_disabled';
    case Revoked = 'revoked';
    case NotInRollout = 'not_in_rollout';
    case Plan = 'plan';
}
