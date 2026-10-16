<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Tenant;
use Illuminate\Support\Facades\Cache;

/**
 * The organisation a host belongs to, for the pages Apache hands Laravel on
 * an organisation's own host (`/login`, `/news`, `/news/{id}`): one of its
 * subdomains, or its verified custom domain.
 *
 * The host is the only selector: no header, no query, no form field, so
 * nothing a visitor sends can make one organisation's page carry another's
 * words. The apex, its www alias, the reserved names and a deployment with
 * no APP_DOMAIN name no organisation. `Tenant` is a global model (no tenant
 * scope to bypass); each lookup states the host it is for, the same way
 * ResolveTenant's do, and shares its cache keys.
 */
final class OrganisationHost
{
    public static function tenant(string $rawHost): ?Tenant
    {
        $root = TenancyDomain::root();
        $host = Tenant::normaliseDomain($rawHost);
        if ($root === null || $host === null || $host === $root || $host === 'www.'.$root) {
            return null;
        }

        if (str_ends_with($host, '.'.$root)) {
            $label = substr($host, 0, -strlen('.'.$root));
            if ($label === '' || str_contains($label, '.') || in_array($label, Tenant::RESERVED_SUBDOMAINS, true)) {
                return null;
            }
            $tenant = Cache::remember("tenant:{$label}", 300, fn () => Tenant::where('subdomain', $label)->first());
        } else {
            $found = Cache::remember(
                "tenant-domain:{$host}",
                300,
                fn () => Tenant::where('custom_domain', $host)->whereNotNull('custom_domain_verified_at')->first() ?? false,
            );
            $tenant = $found instanceof Tenant ? $found : null;
        }

        return $tenant instanceof Tenant && $tenant->isActive() ? $tenant : null;
    }
}
