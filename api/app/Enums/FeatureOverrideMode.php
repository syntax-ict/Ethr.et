<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * One organisation's exception to a feature's platform state. A grant beats
 * the plan and Restricted; nothing beats platform Disabled.
 */
enum FeatureOverrideMode: string
{
    case Grant = 'grant';
    case Revoke = 'revoke';
}
