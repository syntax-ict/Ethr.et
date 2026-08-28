import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { apiClient } from "@/api/client";
import { usePermissions } from "@/lib/hooks/usePermissions";
import type { PaginatedResponse } from "@/api/types";

/**
 * Every endpoint in this module is super-admin only.
 *
 * `RoleGate` guards the *render*, but hooks are called unconditionally at the
 * top of a component — so a tenant admin who opened `/admin` still fired four
 * privileged requests, collected four 403s in the console, and left four
 * authorisation failures in the server log that look indistinguishable from
 * probing. Gating each query on the caller's role stops the request at the
 * source rather than discarding its rejection.
 */
function useIsSuperAdmin(): boolean {
  return usePermissions().isSuperAdmin;
}

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

/**
 * `Omit<..., "employee_count">` is deliberate: only the *list* endpoint returns
 * that field. The detail endpoint reports headcount under `usage.employees`, so
 * inheriting it unchanged made the type claim a field the response never
 * carries — and the detail page duly rendered the string "Undefined" in its
 * Employees tile and profile row, with tsc none the wiser.
 */
export interface AdminTenantDetail extends Omit<AdminTenant, "employee_count"> {
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
  const isSuperAdmin = useIsSuperAdmin();

  return useQuery<PaginatedResponse<AdminTenant>>({
    queryKey: ["admin", "tenants", params],
    enabled: isSuperAdmin,
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

/**
 * Start an impersonation session.
 *
 * The `token` in the response is for API clients that send a bearer header;
 * this app does not — the server puts the same token in the httpOnly session
 * cookie, which is what makes the reload below land as the tenant admin. The
 * localStorage entries only carry the tenant header and the banner flag.
 */
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
      const currentTenant = localStorage.getItem("tenant");
      if (currentTenant) localStorage.setItem("original_tenant", currentTenant);

      localStorage.setItem("tenant", data.tenant);
      localStorage.setItem("impersonating", "true");

      // Full reload, not a router push: the identity behind every cached query
      // just changed, so the client cache has to be dropped wholesale.
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
  const isSuperAdmin = useIsSuperAdmin();

  return useQuery<PaginatedResponse<FailedJob>>({
    queryKey: ["admin", "failed-jobs", params],
    enabled: isSuperAdmin,
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

/**
 * Drop a failed job without re-running it — for the ones that can never
 * succeed. Without this the console's "Attention Required" banner could only
 * ever grow, and an operator learns to ignore a counter that never clears.
 */
export function useDismissFailedJob() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (uuid: string) => {
      await apiClient.delete(`/admin/failed-jobs/${uuid}`);
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

// ── Platform analytics hooks ─────────────────────────────────────────────────

export interface AdminRevenue {
  mrr_cents: number;
  total_tenants: number;
  active_tenants: number;
  trial_tenants: number;
  suspended_tenants: number;
  cancelled_tenants: number;
  conversion_rate: number;
  monthly_trend: Array<{ month: string; revenue_cents: number }>;
}

export function useAdminRevenue() {
  const isSuperAdmin = useIsSuperAdmin();

  return useQuery<AdminRevenue>({
    queryKey: ["admin", "revenue"],
    queryFn: async () => {
      const { data } = await apiClient.get("/admin/revenue");
      return data;
    },
    staleTime: 5 * 60 * 1000,
    enabled: isSuperAdmin,
  });
}

export interface AdminHealthService {
  status: string;
  response_ms?: number;
  error?: string;
  note?: string;
}

export interface AdminHealth {
  services: Record<string, AdminHealthService>;
  queue: Record<string, { depth: number | null; error?: string }>;
  failed_jobs: number;
  resources?: {
    php_memory_mb: number;
    php_peak_memory_mb: number;
    disk_free_gb: number | null;
  };
}

export function useAdminHealth() {
  const isSuperAdmin = useIsSuperAdmin();

  return useQuery<AdminHealth>({
    queryKey: ["admin", "health"],
    queryFn: async () => {
      const { data } = await apiClient.get("/admin/health");
      return data;
    },
    refetchInterval: 30_000,
    enabled: isSuperAdmin,
  });
}

export function useAdminAuditLog(params?: {
  action?: string;
  from?: string;
  to?: string;
  page?: number;
}) {
  const isSuperAdmin = useIsSuperAdmin();

  return useQuery<PaginatedResponse<AdminAuditLog>>({
    queryKey: ["admin", "audit", params],
    enabled: isSuperAdmin,
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

/**
 * Platform-wide settings, super admin only.
 *
 * The bank account here is what every tenant's billing page tells them to pay
 * into, so a mistake routes real money to the wrong place — hence the audit
 * entry on the backend and the confirmation step in the UI.
 */
export interface PlatformSettings {
  public_id: string;
  bank_name: string | null;
  bank_account_number: string | null;
  bank_account_name: string | null;
  payment_instructions: string | null;
  payment_instructions_am: string | null;
  is_configured: boolean;
  updated_at: string | null;
}

export function usePlatformSettings() {
  const isSuperAdmin = useIsSuperAdmin();

  return useQuery<PlatformSettings>({
    queryKey: ["admin", "platform-settings"],
    queryFn: async () => {
      const { data } = await apiClient.get("/admin/platform-settings");
      return data;
    },
    enabled: isSuperAdmin,
  });
}

export function useUpdatePlatformSettings() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (payload: Partial<PlatformSettings>) => {
      const { data } = await apiClient.put("/admin/platform-settings", payload);
      return data;
    },
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ["admin", "platform-settings"] });
      // Tenants read these values on their billing page.
      qc.invalidateQueries({ queryKey: ["billing", "dashboard"] });
    },
  });
}
