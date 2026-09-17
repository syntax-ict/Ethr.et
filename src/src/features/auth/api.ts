import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { apiClient } from "@/api/client";
import type { User } from "@/api/types";

interface MeResponse {
  user: User;
  /** Abilities resolved server-side from the base role or custom role. */
  permissions: string[];
  tenant: {
    public_id: string;
    name: string;
    subdomain: string;
    status: string;
    /**
     * IANA zone the tenant displays timestamps in, e.g. `Africa/Addis_Ababa`.
     * `TenantResource` has always sent this; the type simply did not declare
     * it, so nothing on the frontend could use it. See BASELINE §12g.
     */
    timezone?: string | null;
    logo_path?: string | null;
    theme?: {
      primary_color?: string;
      secondary_color?: string;
      accent_color?: string;
    } | null;
  } | null;
}

const meQueryOptions = {
  queryKey: ["auth", "me"],
  queryFn: async () => {
    const { data } = await apiClient.get<MeResponse>("/auth/me");
    return data;
  },
  retry: false,
  staleTime: 5 * 60 * 1000,
} as const;

export function useCurrentUser() {
  return useQuery({
    ...meQueryOptions,
    select: (data: MeResponse) => data.user,
  });
}

export function useCurrentTenant() {
  return useQuery({
    ...meQueryOptions,
    select: (data: MeResponse) => data.tenant,
  });
}

export function useCurrentPermissions() {
  return useQuery({
    ...meQueryOptions,
    select: (data: MeResponse) => data.permissions ?? [],
  });
}

export function useLogout() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async () => {
      await apiClient.post("/auth/logout");
    },
    // `onSettled`, not `onSuccess`. Signing out is a decision about this
    // device; the request is how we additionally ask the server to revoke the
    // token, and it is worth attempting, but it cannot be what decides whether
    // the local session ends.
    //
    // The app holds one QueryClient for its whole lifetime (`app/providers.tsx`
    // creates it in `useState` and never replaces it), so everything fetched
    // during the session stays in memory until this runs. Under `onSuccess`, a
    // failed request — offline, API down, token already expired — did nothing
    // at all: no clear, no navigation, and neither call site passes an
    // `onError`. The person clicked Log Out and was left on a populated
    // dashboard believing they had. For an offline-first product that is not an
    // edge case.
    onSettled: () => {
      queryClient.clear();
      window.location.href = "/login";
    },
  });
}
