/**
 * Where a tenant's application actually lives.
 *
 * In production the hostname is the only tenant selector the API honours, so a
 * session established on the apex (`ethr.et`) is useless: the session cookie is
 * host-only by design — that is what stops `habru.ethr.et`'s cookie from ever
 * reaching `woldia.ethr.et` — and so it does not travel to the tenant host.
 *
 * Authenticating on the apex and then redirecting would therefore drop the
 * session on arrival. Instead the apex login form *routes*: it sends the browser
 * to the tenant's own login page and the credentials are entered there, on the
 * host that will hold the cookie. No credential ever crosses a host boundary.
 *
 * Behaviour is driven by explicit configuration rather than NODE_ENV, so a
 * production build running locally behaves like local development:
 *
 *   NEXT_PUBLIC_ROOT_DOMAIN unset  → single-host mode (localhost dev, tests).
 *                                     The tenant travels in the X-Tenant header,
 *                                     which the API accepts only in local/testing.
 *   NEXT_PUBLIC_ROOT_DOMAIN set    → hostname is authoritative. The apex routes
 *                                     to {tenant}.{root}; X-Tenant is not sent.
 */

import { tenantFromHost } from "./host-context";

const ROOT_DOMAIN = process.env.NEXT_PUBLIC_ROOT_DOMAIN?.trim() || null;

/** True when tenancy is carried by the hostname rather than a header. */
export function hostnameIsAuthoritative(): boolean {
  return ROOT_DOMAIN !== null;
}

/**
 * The absolute URL of `path` on `tenant`'s host, or null when the current host
 * already is that tenant (nothing to redirect to) or when no root domain is
 * configured (single-host development).
 */
export function tenantHostUrl(tenant: string, path = "/login"): string | null {
  if (!ROOT_DOMAIN || typeof window === "undefined") return null;

  const slug = tenant.trim().toLowerCase();
  if (slug === "") return null;

  // Via the root-domain-aware classifier, not label counting: the older helper
  // read `admin.ethr.et` as a tenant named "admin", so a super admin who typed
  // "admin" into the apex form was told there was nowhere to go.
  if (tenantFromHost(window.location.host, ROOT_DOMAIN) === slug) return null;

  return `${window.location.protocol}//${slug}.${ROOT_DOMAIN}${path}`;
}
