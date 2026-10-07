import { SITE_URL } from "@/lib/site-url";

/**
 * The address a person types to reach an organisation, as the UI should print it.
 *
 * Two shapes, chosen by the same switch the rest of the frontend uses
 * (`NEXT_PUBLIC_ROOT_DOMAIN`, see `lib/auth/tenant-host.ts`):
 *
 *   root domain set    → subdomain mode:   `acme.ethr.et`
 *   root domain unset  → single-host mode: `ethr.et/acme`
 *
 * Production builds in single-host mode, because the host serves no wildcard
 * subdomains (M3 — docs/decisions/OWNER-DECISION-TENANCY-WITHOUT-SUBDOMAINS.md).
 * There `acme.ethr.et` does not answer, so printing it sends people to a Plesk
 * login page. The path form is what `OrganisationEntryController` serves.
 *
 * A custom domain, where the platform has assigned one, wins over both: it is
 * the address the organisation actually uses.
 */

export interface TenantAddressConfig {
  /** `NEXT_PUBLIC_ROOT_DOMAIN`, or null in single-host mode. */
  rootDomain: string | null;
  /** The host the single-host app is served from — `ethr.et` in production. */
  siteHost: string;
}

const ENV_CONFIG: TenantAddressConfig = {
  rootDomain: process.env.NEXT_PUBLIC_ROOT_DOMAIN?.trim() || null,
  siteHost: new URL(SITE_URL).host,
};

/** `acme.ethr.et`, `ethr.et/acme`, or the custom domain when one is given. */
export function tenantAddress(
  slug: string,
  customDomain: string | null = null,
  config: TenantAddressConfig = ENV_CONFIG,
): string {
  if (customDomain) return customDomain;
  return config.rootDomain
    ? `${slug}.${config.rootDomain}`
    : `${config.siteHost}/${slug}`;
}

/**
 * The fixed text around a slug input. Exactly one side is non-null: a suffix
 * (`.ethr.et`) in subdomain mode, a prefix (`ethr.et/`) in single-host mode.
 */
export function tenantAddressAffixes(
  config: TenantAddressConfig = ENV_CONFIG,
): { prefix: string | null; suffix: string | null } {
  return config.rootDomain
    ? { prefix: null, suffix: `.${config.rootDomain}` }
    : { prefix: `${config.siteHost}/`, suffix: null };
}
