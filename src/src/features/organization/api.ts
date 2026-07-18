import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { apiClient } from "@/api/client";
import type { PaginatedResponse } from "@/api/types";

export interface Branch {
  public_id: string;
  name: string;
  name_am?: string | null;
  code?: string | null;
  address?: string | null;
  city?: string | null;
  phone?: string | null;
  latitude?: number | null;
  longitude?: number | null;
  geofence_radius_meters?: number | null;
  is_active: boolean;
  departments_count?: number;
  employees_count?: number;
}

export interface Department {
  public_id: string;
  name: string;
  name_am?: string | null;
  code?: string | null;
  is_active: boolean;
  parent?: { public_id: string; name: string } | null;
  branch?: { public_id: string; name: string } | null;
}

export interface Team {
  public_id: string;
  name: string;
  name_am?: string | null;
  is_active: boolean;
  department?: { public_id: string; name: string } | null;
}

export interface Position {
  public_id: string;
  title: string;
  title_am?: string | null;
  code?: string | null;
  description?: string | null;
  is_active: boolean;
  employees_count?: number;
}

export interface Grade {
  public_id: string;
  name: string;
  min_salary_cents: number;
  max_salary_cents: number;
  sort_order?: number;
}

export interface CostCenter {
  public_id: string;
  name: string;
  code?: string | null;
  is_active: boolean;
}

function makeHooks<T extends { public_id: string }>(resource: string) {
  return {
    useList: () =>
      useQuery<PaginatedResponse<T>>({
        queryKey: ["organization", resource],
        queryFn: async () => {
          const { data } = await apiClient.get(`/organization/${resource}`);
          return data;
        },
      }),

    useCreate: () => {
      const qc = useQueryClient();
      return useMutation({
        mutationFn: async (payload: Partial<T>) => {
          const { data } = await apiClient.post(
            `/organization/${resource}`,
            payload,
          );
          return data;
        },
        onSuccess: () =>
          qc.invalidateQueries({ queryKey: ["organization", resource] }),
      });
    },

    useUpdate: () => {
      const qc = useQueryClient();
      return useMutation({
        mutationFn: async ({
          publicId,
          payload,
        }: {
          publicId: string;
          payload: Partial<T>;
        }) => {
          const { data } = await apiClient.put(
            `/organization/${resource}/${publicId}`,
            payload,
          );
          return data;
        },
        onSuccess: () =>
          qc.invalidateQueries({ queryKey: ["organization", resource] }),
      });
    },

    useDelete: () => {
      const qc = useQueryClient();
      return useMutation({
        mutationFn: async (publicId: string) => {
          await apiClient.delete(`/organization/${resource}/${publicId}`);
        },
        onSuccess: () =>
          qc.invalidateQueries({ queryKey: ["organization", resource] }),
      });
    },
  };
}

export const branchesApi = makeHooks<Branch>("branches");
export const departmentsApi = makeHooks<Department>("departments");
export const teamsApi = makeHooks<Team>("teams");
export const positionsApi = makeHooks<Position>("positions");
export const gradesApi = makeHooks<Grade>("grades");
export const costCentersApi = makeHooks<CostCenter>("cost-centers");
