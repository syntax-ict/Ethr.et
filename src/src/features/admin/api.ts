import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { apiClient } from "@/api/client";
import type { PaginatedResponse } from "@/api/types";

export interface AdminTenant {
  public_id: string;
  name: string;
  subdomain: string;
  type: string | null;
  status: string;
  employee_count: number;
  trial_ends_at: string | null;
  created_at: string;
}

export interface AdminTenantDetail extends AdminTenant {
  updated_at: string;
  usage: { employees: number; devices: number } | null;
  subscription: {
    plan_name: string | null;
    status: string | null;
    current_period_end: string | null;
  } | null;
  invoices: Array<{
    public_id: string;
    total_cents: number;
    status: string;
    due_date: string | null;
    paid_at: string | null;
  }>;
  audit_log: Array<{ action: string; created_at: string }>;
}

export function useAdminTenants(params?: {
  search?: string;
  status?: string;
  page?: number;
  per_page?: number;
}) {
  return useQuery<PaginatedResponse<AdminTenant>>({
    queryKey: ["admin", "tenants", params],
    queryFn: async () => {
      const queryParams: Record<string, unknown> = {};
      if (params?.search) queryParams.search = params.search;
      if (params?.status) queryParams["filter[status]"] = params.status;
      if (params?.page) queryParams.page = params.page;
      if (params?.per_page) queryParams.per_page = params.per_page;

      const { data } = await apiClient.get("/admin/tenants", {
        params: queryParams,
      });
      return data;
    },
    staleTime: 2 * 60 * 1000,
  });
}

export function useAdminTenant(publicId: string) {
  return useQuery<AdminTenantDetail>({
    queryKey: ["admin", "tenants", publicId],
    queryFn: async () => {
      const { data } = await apiClient.get(`/admin/tenants/${publicId}`);
      return data;
    },
    enabled: !!publicId,
    staleTime: 2 * 60 * 1000,
  });
}

export function useUpdateTenantStatus() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async ({
      publicId,
      status,
    }: {
      publicId: string;
      status: "active" | "suspended" | "cancelled";
    }) => {
      const { data } = await apiClient.put(
        `/admin/tenants/${publicId}/status`,
        { status },
      );
      return data;
    },
    onSuccess: () => qc.invalidateQueries({ queryKey: ["admin", "tenants"] }),
  });
}

export function useExtendTrial() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async ({
      publicId,
      days,
    }: {
      publicId: string;
      days: number;
    }) => {
      const { data } = await apiClient.post(
        `/admin/tenants/${publicId}/extend-trial`,
        { days },
      );
      return data;
    },
    onSuccess: () => qc.invalidateQueries({ queryKey: ["admin", "tenants"] }),
  });
}

export function useImpersonateTenant() {
  return useMutation<
    { token: string; tenant: string; expires_at: string },
    unknown,
    { publicId: string; code: string }
  >({
    mutationFn: async ({ publicId, code }) => {
      const { data } = await apiClient.post(
        `/admin/tenants/${publicId}/impersonate`,
        { code },
      );
      return data;
    },
    onSuccess: (data) => {
      // Preserve original credentials for exit
      const current = localStorage.getItem("access_token");
      const currentTenant = localStorage.getItem("tenant");
      if (current) localStorage.setItem("original_access_token", current);
      if (currentTenant) localStorage.setItem("original_tenant", currentTenant);

      // Switch to impersonated session
      localStorage.setItem("access_token", data.token);
      localStorage.setItem("tenant", data.tenant);
      localStorage.setItem("impersonating", "true");

      window.location.href = "/dashboard";
    },
  });
}

export interface AdminAuditLog {
  action: string;
  auditable_type?: string;
  auditable_id?: number;
  user_id?: number;
  ip_address?: string;
  created_at: string;
}

export function useTenantBackup() {
  return useMutation({
    mutationFn: async (publicId: string) => {
      const { data } = await apiClient.post(
        `/admin/tenants/${publicId}/backup`,
      );
      return data;
    },
  });
}

export interface FailedJob {
  uuid: string;
  connection: string;
  queue: string;
  payload: string;
  exception: string;
  failed_at: string;
}

export function useFailedJobs(params?: { page?: number }) {
  return useQuery<PaginatedResponse<FailedJob>>({
    queryKey: ["admin", "failed-jobs", params],
    queryFn: async () => {
      const { data } = await apiClient.get("/admin/failed-jobs", {
        params: { page: params?.page ?? 1 },
      });
      return data;
    },
  });
}

export function useRetryFailedJob() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (uuid: string) => {
      const { data } = await apiClient.post(`/admin/failed-jobs/${uuid}/retry`);
      return data;
    },
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ["admin", "failed-jobs"] });
      qc.invalidateQueries({ queryKey: ["admin", "health"] });
    },
  });
}

export function useRetryAllFailedJobs() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async () => {
      const { data } = await apiClient.post("/admin/failed-jobs/retry-all");
      return data;
    },
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ["admin", "failed-jobs"] });
      qc.invalidateQueries({ queryKey: ["admin", "health"] });
    },
  });
}

export function useAdminAuditLog(params?: {
  action?: string;
  from?: string;
  to?: string;
  page?: number;
}) {
  return useQuery<PaginatedResponse<AdminAuditLog>>({
    queryKey: ["admin", "audit", params],
    queryFn: async () => {
      const queryParams: Record<string, unknown> = { per_page: 50 };
      if (params?.action) queryParams["filter[action]"] = params.action;
      if (params?.from) queryParams["filter[from]"] = params.from;
      if (params?.to) queryParams["filter[to]"] = params.to;
      if (params?.page) queryParams.page = params.page;

      const { data } = await apiClient.get("/admin/audit", {
        params: queryParams,
      });
      return data;
    },
  });
}
