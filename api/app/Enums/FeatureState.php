<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * A feature's state for the whole platform.
 *
 * Hidden takes the feature off the screen and leaves the API working, so it is
 * never a way to stop anything: that is Disabled. Restricted is on only for
 * organisations holding a grant.
 */
enum FeatureState: string
{
    case Enabled = 'enabled';
    case Hidden = 'hidden';
    case Restricted = 'restricted';
    case Disabled = 'disabled';
}
