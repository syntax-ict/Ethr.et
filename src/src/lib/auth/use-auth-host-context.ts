"use client";

import { useQuery } from "@tanstack/react-query";
import { usePathname } from "next/navigation";
import axios from "axios";
import { useHydrated } from "@/lib/hooks/useHydrated";
import { entryOrganisation } from "./entry-organisation";
import { apiClient } from "@/api/client";
import { hostContext, tenantFromHost, type HostContext } from "./host-context";
import { useHost } from "./host-provider";

const ROOT_DOMAIN = process.env.NEXT_PUBLIC_ROOT_DOMAIN?.trim() || null;

export interface TenantBrand {
  /** An https logo URL, or null for the organisation's initial. */
  logo_url: string | null;
  primary_color: string | null;
  secondary_color?: string | null;
  accent_color: string | null;
}

/** The organisation's own words and look on its sign-in page. */
export interface TenantLanding {
  headline: string | null;
  message: string | null;
  support_email: string | null;
  support_phone: string | null;
  background: "brand" | "gradient" | "image";
  background_image_url: string | null;
}

/** Which ways in the organisation offers besides a password. */
export interface TenantSignIn {
  sso: boolean;
  otp: boolean;
}

interface TenantContextResponse {
  tenant: {
    name: string;
    subdomain: string;
    logo_path: string | null;
    brand?: TenantBrand;
    landing?: TenantLanding;
    sign_in?: TenantSignIn;
    default_locale?: string;
  } | null;
}

type TenantLookupError = "not_found" | "inactive" | null;

/**
 * Single source of truth for "which ETHR application is this auth page on",
 * shared by every login-family page instead of each page re-deriving it.
 *
 * Built on hostContext()/tenantFromHost() — the same classifier middleware.ts
 * and the API client use. The label-counting helper this replaced knew nothing
 * of NEXT_PUBLIC_ROOT_DOMAIN, so it read `admin.ethr.et` as a tenant named
 * "admin" instead of the platform host, and three auth pages inherited that
 * misclassification independently. There is now one implementation, which is
 * the only way it can stay in step with the API's ResolveTenant.
 */
export function useAuthHostContext() {
  // From the server's request headers, not `window.location.host`. Deriving it
  // from `window` made `host` null during SSR and the real hostname on the
  // client, so under subdomain tenancy the two renders disagreed about the
  // heading and about whether the organisation field is shown — React
  // hydration error #418 on every tenant login page. See host-provider.tsx.
  const host = useHost();
  const classified: HostContext = hostContext(host, ROOT_DOMAIN);
  const slugFromHost = tenantFromHost(host, ROOT_DOMAIN);

  const query = useQuery({
    queryKey: ["auth", "tenant-context", slugFromHost],
    queryFn: async () => {
      const { data } = await apiClient.get<TenantContextResponse>(
        "/auth/tenant-context",
      );
      return data.tenant;
    },
    enabled: classified === "tenant",
    retry: false,
    staleTime: 5 * 60 * 1000,
  });

  // A host this build cannot classify may still name an organisation to the
  // server: a verified custom domain (`hr.acme.com`), or a subdomain when the
  // build has no NEXT_PUBLIC_ROOT_DOMAIN, which is how production builds (M3).
  // Ask, but WITHOUT X-Tenant, so only the host can answer: through apiClient
  // the apex would name whatever organisation this browser last used and lock
  // the field to it. On the apex the server answers null and nothing changes.
  // Found in the 2026-10-08 QA pass: the Enterprise sign-in page did not name
  // the organisation, and asked for it in a field, on that organisation's own
  // domain (docs/audit/QA-CANONICAL-ADDRESS-2026-10-08.md, finding 2).
  const hostQuery = useQuery({
    queryKey: ["auth", "host-tenant", host],
    queryFn: async () => {
      const { data } = await axios.get<TenantContextResponse>(
        "/api/v1/auth/tenant-context",
        { withCredentials: true, headers: { Accept: "application/json" } },
      );
      return data.tenant;
    },
    enabled: classified === "unknown" && !!host,
    retry: false,
    staleTime: 5 * 60 * 1000,
  });

  const hostTenant = hostQuery.data ?? null;

  // The shared landing: on the shared host, the address `/{slug}` names the
  // organisation (see entryOrganisation). Read after hydration only - the
  // page was prerendered as /login, and naming the organisation during the
  // first render would disagree with that HTML (React #418). Asked with
  // X-Tenant, which the server honours on the shared host only.
  const pathname = usePathname();
  const hydrated = useHydrated();
  const entrySlug =
    hydrated && (classified === "public" || classified === "unknown")
      ? entryOrganisation(pathname)
      : null;
  const entryQuery = useQuery({
    queryKey: ["auth", "entry-tenant", entrySlug],
    queryFn: async () => {
      const { data } = await axios.get<TenantContextResponse>(
        "/api/v1/auth/tenant-context",
        {
          withCredentials: true,
          headers: { Accept: "application/json", "X-Tenant": entrySlug },
        },
      );
      return data.tenant;
    },
    enabled:
      !!entrySlug &&
      (classified === "public" || (hostQuery.isFetched && !hostTenant)),
    retry: false,
    staleTime: 5 * 60 * 1000,
  });
  const entryTenant = entryQuery.data ?? null;
  const onLanding = !!entrySlug && !hostTenant;

  const context: HostContext =
    (classified === "unknown" && hostTenant) || (onLanding && entryTenant)
      ? "tenant"
      : classified;
  const tenantSlug =
    slugFromHost ??
    hostTenant?.subdomain ??
    (onLanding ? (entryTenant?.subdomain ?? entrySlug) : null);

  const resolved = query.data ?? hostTenant ?? entryTenant;
  const errorType = (
    (query.error ??
      hostQuery.error ??
      (onLanding ? entryQuery.error : null)) as
      { response?: { data?: { type?: string } } } | undefined
  )?.response?.data?.type;

  let tenantLookupError: TenantLookupError = null;
  if ((context === "tenant" || onLanding) && errorType) {
    if (errorType.endsWith("tenant-not-found")) tenantLookupError = "not_found";
    else if (errorType.endsWith("tenant-inactive"))
      tenantLookupError = "inactive";
  }

  return {
    /** "public" (apex) | "platform" (admin host) | "tenant" | "unknown" (dev, no ROOT_DOMAIN). */
    context,
    /** The tenant slug from the hostname, or null off a tenant host. */
    tenantSlug,
    tenantName: resolved?.name ?? null,
    tenantLogoPath: resolved?.logo_path ?? null,
    /** The organisation's logo and colours, when it names itself to this page. */
    tenantBrand: resolved?.brand ?? null,
    /** Its headline, message, help contacts and background, if it set any. */
    tenantLanding: resolved?.landing ?? null,
    /** SSO and text-message sign-in, where the organisation offers them. */
    tenantSignIn: resolved?.sign_in ?? null,
    tenantDefaultLocale: resolved?.default_locale ?? null,
    /** On an organisation's own landing address, `ethr.et/{slug}`. */
    onLanding,
    tenantContextLoading:
      (context === "tenant" && (query.isLoading || hostQuery.isLoading)) ||
      (onLanding && entryQuery.isLoading),
    tenantLookupError,
    /**
     * Manual organization-subdomain field stays only where the hostname
     * doesn't already identify the organization: the apex (routes onward to
     * the tenant's own host) and local/dev where ROOT_DOMAIN isn't set.
     */
    tenantFieldVisible:
      context !== "tenant" && context !== "platform" && !onLanding,
  };
}
