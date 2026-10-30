<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How the console groups the catalogue. A module is a plan feature; an
 * integration is a door to or from another system; a public surface is a page
 * anyone can open without signing in.
 */
enum FeatureKind: string
{
    case Module = 'module';
    case Integration = 'integration';
    case PublicSurface = 'public';
}
