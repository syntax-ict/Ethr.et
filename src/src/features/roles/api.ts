import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { apiClient } from "@/api/client";
import type { PaginatedResponse } from "@/api/types";

export interface CustomRole {
  public_id: string;
  name: string;
  description: string;
  is_active: boolean;
  permissions: string[];
  users_count?: number;
  created_at: string;
  updated_at: string;
}

export interface PermissionEntry {
  name: string;
  module: string;
  action: string;
  description: string;
}

export type PermissionsByModule = Record<string, PermissionEntry[]>;

export function useCustomRoles(params?: {
  page?: number;
  per_page?: number;
  search?: string;
}) {
  return useQuery<PaginatedResponse<CustomRole>>({
    queryKey: ["custom-roles", params],
    queryFn: async () => {
      const { data } = await apiClient.get("/roles", { params });
      return data;
    },
    staleTime: 30 * 60 * 1000,
  });
}

export function useCustomRole(publicId: string) {
  return useQuery<CustomRole>({
    queryKey: ["custom-roles", publicId],
    queryFn: async () => {
      const { data } = await apiClient.get(`/roles/${publicId}`);
      return data;
    },
    enabled: !!publicId,
    staleTime: 30 * 60 * 1000,
  });
}

export function usePermissions() {
  return useQuery<PermissionsByModule>({
    queryKey: ["permissions"],
    queryFn: async () => {
      const { data } = await apiClient.get("/permissions");
      return data;
    },
    staleTime: 30 * 60 * 1000,
  });
}

export function useCreateCustomRole() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (payload: {
      name: string;
      description?: string;
      permissions: string[];
    }) => {
      const { data } = await apiClient.post("/roles", payload);
      return data as CustomRole;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["custom-roles"] });
    },
  });
}

export function useUpdateCustomRole(publicId: string) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (
      payload: Partial<{
        name: string;
        description: string;
        is_active: boolean;
        permissions: string[];
      }>,
    ) => {
      const { data } = await apiClient.put(`/roles/${publicId}`, payload);
      return data as CustomRole;
    },
    onSuccess: (_data, payload) => {
      queryClient.invalidateQueries({ queryKey: ["custom-roles"] });

      // A change to the role's abilities may be a change to *mine*, if I hold
      // it. `/auth/me` returns the resolved permission set but not which custom
      // role produced it, so the client cannot tell — and editing a role is a
      // rare administrative action, so the refetch is taken rather than
      // guessed at.
      //
      // Renaming or deactivating a role cannot change anybody's abilities, so
      // those edits are left alone.
      if (payload.permissions !== undefined) {
        queryClient.invalidateQueries({ queryKey: ["auth", "me"] });
      }
    },
  });
}

export function useDeleteCustomRole() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (publicId: string) => {
      await apiClient.delete(`/roles/${publicId}`);
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["custom-roles"] });
    },
  });
}
