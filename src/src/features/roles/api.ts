import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { apiClient } from "@/api/client";
import type { components } from "@/api/generated";
import type { PaginatedResponse } from "@/api/types";

type Schemas = components["schemas"];

/**
 * `permissions` is `pluck('name')` over the loaded relation, which Scramble
 * publishes as `unknown[]`; it is the role's permission names. `index`, `show`,
 * `store` and `update` all load it.
 */
export type CustomRole = Omit<Schemas["CustomRoleResource"], "permissions"> & {
  permissions: string[];
};

/**
 * One row of `GET /permissions`: `Permission` rows selected as these four
 * columns and grouped by `module`. Scramble reads the `groupBy` as
 * `string[][]`, so the shape is stated here from CustomRoleController.
 */
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
    mutationFn: async (payload: Schemas["StoreCustomRoleRequest"]) => {
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
    mutationFn: async (payload: Schemas["UpdateCustomRoleRequest"]) => {
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
