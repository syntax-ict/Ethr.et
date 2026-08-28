/**
 * Which of the three ETHR applications a hostname refers to.
 *
 *   ethr.et          → "public"    marketing site, no tenant
 *   admin.ethr.et    → "platform"  super admin console, never a tenant
 *   habru.ethr.et    → "tenant"    tenant application
 *   anything else    → "unknown"
 *
 * A pure function taking the root domain as an argument rather than reading
 * NEXT_PUBLIC_ROOT_DOMAIN itself, so the classification can be tested without
 * a module registry reset — and so middleware, client code and tests all agree
 * on one implementation rather than three copies of the same string arithmetic.
 *
 * Mirrors the backend's ResolveTenant::extractSubdomain(). The two must stay in
 * step: if the browser thinks it is on a tenant host and Laravel does not, the
 * user sees an application with no data in it.
 */
export type HostContext = "public" | "platform" | "tenant" | "unknown";

/**
 * Labels the API refuses to resolve as a tenant — `Tenant::RESERVED_SUBDOMAINS`.
 *
 * Kept in step by hand, as the two host classifiers already are. Without it the
 * browser read `www.ethr.et` as a tenant named "www" and rendered "Sign in to
 * www" with the organisation field hidden, while `GET /auth/tenant-context`
 * answered `{tenant: null}` — a login form on an apex alias with no way to say
 * which organisation you belong to.
 */
const RESERVED_LABELS = new Set([
  "admin",
  "platform",
  "api",
  "app",
  "www",
  "mail",
  "smtp",
  "ftp",
  "cdn",
  "static",
  "assets",
  "status",
  "support",
  "help",
  "docs",
  "staging",
  "dev",
  "test",
]);

/** The one label platform administration is served from — `Tenant::PLATFORM_SUBDOMAIN`. */
const PLATFORM_LABEL = "admin";

export function hostContext(
  host: string | null | undefined,
  rootDomain: string | null | undefined,
): HostContext {
  const root = rootDomain
    ?.trim()
    .toLowerCase()
    .replace(/^\.+|\.+$/g, "");
  if (!root || !host) return "unknown";

  // Strip the port — `admin.ethr.et:443` is still the platform host.
  const hostname = host.split(":")[0].trim().toLowerCase();
  if (hostname === "") return "unknown";

  if (hostname === root) return "public";

  if (hostname.endsWith(`.${root}`)) {
    const label = hostname.slice(0, -(root.length + 1));

    // Exactly one label in front of the apex. `a.b.ethr.et` is neither routed
    // by nginx nor covered by the wildcard certificate, so it is not a tenant.
    if (label === "" || label.includes(".")) return "unknown";

    if (label === PLATFORM_LABEL) return "platform";

    // Every other reserved name behaves as the apex does: no tenant resolves
    // there, so the login form must still ask which organisation is meant.
    return RESERVED_LABELS.has(label) ? "public" : "tenant";
  }

  return "unknown";
}

/** The tenant slug for a tenant host, or null for every other context. */
export function tenantFromHost(
  host: string | null | undefined,
  rootDomain: string | null | undefined,
): string | null {
  if (hostContext(host, rootDomain) !== "tenant") return null;

  const root = rootDomain!
    .trim()
    .toLowerCase()
    .replace(/^\.+|\.+$/g, "");
  const hostname = host!.split(":")[0].trim().toLowerCase();

  return hostname.slice(0, -(root.length + 1));
}
