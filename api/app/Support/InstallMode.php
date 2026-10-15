<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Which shape this installation runs: the multi-tenant SaaS (the default) or
 * a single-tenant on-prem install. config/ethr.php documents both values;
 * docs/ON-PREM.md is the install guide.
 */
final class InstallMode
{
    public static function single(): bool
    {
        return config('ethr.mode') === 'single';
    }

    /**
     * The slug of the organisation a single install serves, or null when the
     * install relies on a sole organisation resolving by itself.
     */
    public static function singleTenantSlug(): ?string
    {
        $slug = config('ethr.single_tenant');

        if (! is_string($slug)) {
            return null;
        }

        $slug = trim($slug);

        return $slug === '' ? null : $slug;
    }
}
