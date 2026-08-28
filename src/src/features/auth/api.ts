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
    onSuccess: () => {
      queryClient.clear();
      window.location.href = "/login";
    },
  });
}
