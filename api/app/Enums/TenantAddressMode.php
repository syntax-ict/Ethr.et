<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How an organisation is reached: the address ETHR puts in every link it
 * hands out, and the one sign-in is handed off to.
 *
 * Until 2026-10-11 this was not a choice. FrontendUrl picked a tier for every
 * organisation at once: a verified custom domain, else the subdomain when the
 * deployment served subdomains, else the path. Now each organisation picks,
 * and the deployment decides only what it CAN serve (`tenancy.subdomains`).
 */
enum TenantAddressMode: string
{
    /** `ethr.et/{slug}`: the shared host, named in X-Tenant after sign-in. */
    case PATH = 'path';

    /** `{slug}.ethr.et`: needs the host to serve wildcard subdomains (M3). */
    case SUBDOMAIN = 'subdomain';

    /** `hr.acme.com`: the organisation's own domain, once verified (Enterprise add-on). */
    case CUSTOM_DOMAIN = 'custom_domain';
}
