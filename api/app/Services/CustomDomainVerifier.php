<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\DnsResolver;
use App\Models\Tenant;
use App\Support\TenancyDomain;

/**
 * Proves an organisation controls the custom domain assigned to it, and that
 * the domain points at this platform.
 *
 * Two DNS records, both required:
 *
 *   - TXT   `_ethr-verification.{domain}` = `ethr-verification={token}`
 *     Ownership. Only someone who controls the zone can publish it, and the
 *     token is issued per assignment (Tenant's saving hook), so a record left
 *     behind for an earlier assignment proves nothing about this one.
 *   - CNAME `{domain}` → the platform target (`tenancy.custom_domain_target`,
 *     or APP_DOMAIN when that is unset). Routing: without it the domain does
 *     not reach the application, and a verified domain that every e-mailed
 *     link points at would be a dead end.
 *
 * A CNAME cannot sit on a zone apex, so `acme.com` itself cannot pass; a
 * subdomain such as `hr.acme.com` is what an organisation brings.
 */
final class CustomDomainVerifier
{
    public const TXT_PREFIX = '_ethr-verification.';

    public const TXT_VALUE_PREFIX = 'ethr-verification=';

    public function __construct(private readonly DnsResolver $dns) {}

    /** Where the organisation publishes its token. */
    public static function txtName(string $domain): string
    {
        return self::TXT_PREFIX.$domain;
    }

    public static function txtValue(string $token): string
    {
        return self::TXT_VALUE_PREFIX.$token;
    }

    /** The host the domain must be a CNAME for, or null when none is configured. */
    public static function target(): ?string
    {
        $target = trim(strtolower((string) config('tenancy.custom_domain_target')), " \t\n\r\0\x0B.");

        return $target !== '' ? $target : TenancyDomain::root();
    }

    /**
     * Each check's outcome for the tenant's current domain and token.
     *
     * @return array{txt: bool, cname: bool}
     */
    public function check(Tenant $tenant): array
    {
        $domain = $tenant->custom_domain;
        $token = $tenant->custom_domain_token;
        $target = self::target();

        if ($domain === null || $token === null || $token === '') {
            return ['txt' => false, 'cname' => false];
        }

        $expected = self::txtValue($token);
        $txt = false;
        foreach ($this->dns->txt(self::txtName($domain)) as $value) {
            if (hash_equals($expected, trim($value))) {
                $txt = true;
                break;
            }
        }

        $cname = $target !== null && $this->dns->cname($domain) === $target;

        return ['txt' => $txt, 'cname' => $cname];
    }
}
