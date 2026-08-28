"use client";

import { useQuery } from "@tanstack/react-query";
import { apiClient } from "@/api/client";
import { hostContext, tenantFromHost, type HostContext } from "./host-context";
import { useHost } from "./host-provider";

const ROOT_DOMAIN = process.env.NEXT_PUBLIC_ROOT_DOMAIN?.trim() || null;

interface TenantContextResponse {
  tenant: { name: string; subdomain: string; logo_path: string | null } | null;
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
  const context: HostContext = hostContext(host, ROOT_DOMAIN);
  const tenantSlug = tenantFromHost(host, ROOT_DOMAIN);

  const query = useQuery({
    queryKey: ["auth", "tenant-context", tenantSlug],
    queryFn: async () => {
      const { data } = await apiClient.get<TenantContextResponse>(
        "/auth/tenant-context",
      );
      return data.tenant;
    },
    enabled: context === "tenant",
    retry: false,
    staleTime: 5 * 60 * 1000,
  });

  const errorType = (
    query.error as { response?: { data?: { type?: string } } } | undefined
  )?.response?.data?.type;

  let tenantLookupError: TenantLookupError = null;
  if (context === "tenant" && errorType) {
    if (errorType.endsWith("tenant-not-found")) tenantLookupError = "not_found";
    else if (errorType.endsWith("tenant-inactive"))
      tenantLookupError = "inactive";
  }

  return {
    /** "public" (apex) | "platform" (admin host) | "tenant" | "unknown" (dev, no ROOT_DOMAIN). */
    context,
    /** The tenant slug from the hostname, or null off a tenant host. */
    tenantSlug,
    tenantName: query.data?.name ?? null,
    tenantLogoPath: query.data?.logo_path ?? null,
    tenantContextLoading: context === "tenant" && query.isLoading,
    tenantLookupError,
    /**
     * Manual organization-subdomain field stays only where the hostname
     * doesn't already identify the organization: the apex (routes onward to
     * the tenant's own host) and local/dev where ROOT_DOMAIN isn't set.
     */
    tenantFieldVisible: context !== "tenant" && context !== "platform",
  };
}
