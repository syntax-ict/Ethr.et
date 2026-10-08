import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import type { components } from "@/api/generated";
import { apiClient } from "@/api/client";
import { usePermissions } from "@/lib/hooks/usePermissions";
import type { operations } from "@/api/generated";
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
  custom_domain: string | null;
  /** `pending` until Verify finds its DNS records; only `verified` resolves. */
  custom_domain_status: UpdateTenantDomainResult["custom_domain_status"];
  type: string | null;
  status: string;
  employee_count: number;
  trial_ends_at: string | null;
  created_at: string;
}

/**
 * The custom domain an organisation is actually reached at, or null.
 *
 * A pending domain is no address: the server resolves nothing on it and builds
 * no link with it, so printing it would send people somewhere that does not
 * answer.
 */
export function verifiedDomain(
  tenant: Pick<AdminTenant, "custom_domain" | "custom_domain_status">,
): string | null {
  return tenant.custom_domain_status === "verified"
    ? tenant.custom_domain
    : null;
}

/**
 * `Omit<..., "employee_count">` is deliberate: only the *list* endpoint returns
 * that field. The detail endpoint reports headcount under `usage.employees`, so
 * inheriting it unchanged made the type claim a field the response never
 * carries — and the detail page duly rendered the string "Undefined" in its
 * Employees tile and profile row, with tsc none the wiser.
 */
export interface AdminTenantDetail extends Omit<AdminTenant, "employee_count"> {
  /** The two records the organisation publishes; null with no domain. */
  custom_domain_dns: UpdateTenantDomainResult["custom_domain_dns"];
  /** Whether the plan includes the `custom_domain` add-on. */
  custom_domain_allowed: boolean;
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

export type UpdateTenantDomainResult =
  operations["adminTenant.updateDomain"]["responses"][200]["content"]["application/json"];

/** `custom_domain: null` clears it; the server normalises what it is given. */
export function useUpdateTenantDomain() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async ({
      publicId,
      ...body
    }: {
      publicId: string;
    } & components["schemas"]["UpdateTenantDomainRequest"]) => {
      const { data } = await apiClient.put<UpdateTenantDomainResult>(
        `/admin/tenants/${publicId}/domain`,
        body,
      );
      return data;
    },
    onSuccess: () => qc.invalidateQueries({ queryKey: ["admin", "tenants"] }),
  });
}

export type VerifyTenantDomainResult =
  operations["adminTenant.verifyDomain"]["responses"][200]["content"]["application/json"];

/**
 * Checks the pending domain's TXT token and CNAME. A 422 carries one message
 * per check that failed, under `errors.txt` and `errors.cname`.
 */
export function useVerifyTenantDomain() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (publicId: string) => {
      const { data } = await apiClient.post<VerifyTenantDomainResult>(
        `/admin/tenants/${publicId}/domain/verify`,
      );
      return data;
    },
    onSuccess: () => qc.invalidateQueries({ queryKey: ["admin", "tenants"] }),
  });
}

/** Confirms an organisation's invoice paid; the provider receives the transfer. */
export function useMarkTenantInvoicePaid(tenantPublicId: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (invoicePublicId: string) => {
      const { data } = await apiClient.put(
        `/admin/tenants/${tenantPublicId}/invoices/${invoicePublicId}/mark-paid`,
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

export type ImpersonateResult =
  operations["adminTenant.impersonate"]["responses"][200]["content"]["application/json"];

/**
 * Only an absolute http(s) URL is followed. Both URLs this module navigates to
 * come from the API, but a navigation target is the one place a `javascript:`
 * scheme would execute, so the check costs nothing and closes that door.
 */
export function isNavigableUrl(value: unknown): value is string {
  if (typeof value !== "string") return false;
  try {
    const { protocol } = new URL(value);
    return protocol === "https:" || protocol === "http:";
  } catch {
    return false;
  }
}

/**
 * Start an impersonation session. The server answers in one of two shapes:
 *
 * - **Hostname mode** (production): a `handoff_url` on the tenant's own host,
 *   carrying a single-use, 30-second nonce in its fragment. The session cookie
 *   is host-only, so nothing here can sign the browser in over there — the
 *   tenant host's claim page does that, and sets the banner flag on *its*
 *   origin. So this origin's storage is left alone: writing `impersonating`
 *   here would put the banner on the admin console instead (audit N19).
 * - **Single host** (local development): console and tenant app share an
 *   origin. The server has already put the token in the httpOnly session
 *   cookie; the `token` in the body is for API clients that send a bearer
 *   header, which this app does not. The localStorage entries carry the tenant
 *   header and the banner flag.
 *
 * Either way a full navigation, not a router push: the identity behind every
 * cached query just changed, so the client cache has to be dropped wholesale.
 */
export function useImpersonateTenant() {
  return useMutation<
    ImpersonateResult,
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
      if ("handoff_url" in data) {
        if (isNavigableUrl(data.handoff_url)) {
          window.location.assign(data.handoff_url);
        }
        return;
      }

      const currentTenant = localStorage.getItem("tenant");
      if (currentTenant) localStorage.setItem("original_tenant", currentTenant);

      localStorage.setItem("tenant", data.tenant);
      localStorage.setItem("impersonating", "true");

      window.location.href = "/dashboard";
    },
  });
}

export type ExitImpersonationResult =
  operations["adminTenant.exitImpersonation"]["responses"][200]["content"]["application/json"];

/**
 * The one call in this module a super admin does not make: it is sent from
 * the impersonation session itself (the tenant admin's identity) and is
 * authorised by that session's `impersonation` token ability. It lives at
 * `/auth/impersonation/exit`, not under `/admin`, because the platform API
 * 404s on a tenant host — which is where an impersonated session lives in
 * production. The server revokes the token and either names a `return_url`
 * on the platform host (hostname mode) or re-issues the super admin's session
 * here and names the tenant to send as X-Tenant (single host). A one-shot call
 * followed by a full navigation, so a plain function rather than a mutation
 * hook.
 */
export async function exitImpersonation(): Promise<ExitImpersonationResult> {
  return (await apiClient.post("/auth/impersonation/exit")).data;
}

/** Same resource as the tenant audit log — never numeric ids (convention 4). */
export type AdminAuditLog = components["schemas"]["AuditLogResource"];

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

/**
 * The services the console should raise an alert for: `unhealthy` only.
 *
 * `disabled` is a service this deployment does not run on purpose (Reverb,
 * with BROADCAST_CONNECTION=null on shared hosting), and SystemHealthService
 * says it is the correct answer. Counting it raised a permanent "Attention
 * Required" in production, which trains operators to ignore it (audit N52).
 * `unknown` means not measured, which is not a fault either.
 */
export function servicesNeedingAttention(
  services: Record<string, AdminHealthService>,
): [string, AdminHealthService][] {
  return Object.entries(services).filter(([, s]) => s.status === "unhealthy");
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

  // The facts the public marketing site states about ETHR. Same row and same
  // permission as the bank details above — see the migration for why these are
  // columns here rather than a new table.
  platform_name: string | null;
  platform_name_am: string | null;
  tagline: string | null;
  tagline_am: string | null;
  logo_url: string | null;
  contact_email: string | null;
  contact_phone: string | null;
  office_address: string | null;
  office_address_am: string | null;
  social_linkedin: string | null;
  social_x: string | null;
  social_facebook: string | null;
  metric_organisations: number | null;
  metric_employees: number | null;
  metric_uptime_note: string | null;
  metric_uptime_note_am: string | null;
  has_published_metrics: boolean;

  // A customer quote, and the provenance that makes it one. The public endpoint
  // refuses to publish the quote unless the author and the consent date are
  // both present, so all three travel together or the landing page shows
  // nothing. `testimonial_consented_on` is admin-only — the public resource
  // never emits it.
  testimonial_quote: string | null;
  testimonial_quote_am: string | null;
  testimonial_author: string | null;
  testimonial_role: string | null;
  testimonial_role_am: string | null;
  testimonial_organisation: string | null;
  testimonial_consented_on: string | null;

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
      // And the public marketing site reads the contact details and metrics
      // from the same row.
      qc.invalidateQueries({ queryKey: ["site-content"] });
    },
  });
}

/**
 * A catalog plan as the admin console sees it — every column, including the
 * ones the public endpoint withholds.
 *
 * Distinct from `Plan` in `features/billing/api.ts`, which is the *public*
 * shape: that one has no `is_active`/`is_public` because the pricing page never
 * receives them. Reusing it here would have meant widening the public type with
 * fields the public endpoint does not send — the class of untruth that put
 * `is_active` into the generated contract for months.
 */
export interface AdminPlan {
  public_id: string;
  name: string;
  slug: string;
  description: string | null;
  description_am: string | null;
  price_cents: number;
  currency: string;
  billing_interval: string;
  max_employees: number | null;
  max_branches: number | null;
  max_devices: number | null;
  features: string[] | null;
  marketing_features: string[] | null;
  marketing_features_am: string[] | null;
  is_active: boolean;
  is_public: boolean;
  is_popular: boolean;
  sort_order: number;
}

export function useAdminPlans() {
  const isSuperAdmin = useIsSuperAdmin();

  return useQuery<AdminPlan[]>({
    queryKey: ["admin", "plans"],
    queryFn: async () => {
      const { data } = await apiClient.get("/admin/plans");
      return data.data;
    },
    enabled: isSuperAdmin,
  });
}

/**
 * Invalidates the *public* catalog too, not only this screen.
 *
 * `/pricing` reads `usePlans()` under the `["plans"]` key. Without this an
 * operator would save a price, watch the admin table update, open the public
 * page in the next tab and see the old figure — and reasonably conclude the
 * save had not worked.
 */
function invalidatePlanCaches(qc: ReturnType<typeof useQueryClient>): void {
  qc.invalidateQueries({ queryKey: ["admin", "plans"] });
  qc.invalidateQueries({ queryKey: ["plans"] });
}

export function useUpdateAdminPlan() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async ({
      publicId,
      ...payload
    }: Partial<AdminPlan> & { publicId: string }) => {
      const { data } = await apiClient.put(`/admin/plans/${publicId}`, payload);
      return data.data as AdminPlan;
    },
    onSuccess: () => invalidatePlanCaches(qc),
  });
}

/** `POST /admin/plans` had no screen; plans were added by hand (audit N101). */
export function useCreateAdminPlan() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (
      payload: Pick<
        AdminPlan,
        "name" | "slug" | "price_cents" | "is_public"
      > & {
        features: string[];
      },
    ) => {
      const { data } = await apiClient.post("/admin/plans", payload);
      return data.data as AdminPlan;
    },
    onSuccess: () => invalidatePlanCaches(qc),
  });
}

export function useRetireAdminPlan() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (publicId: string) => {
      const { data } = await apiClient.delete(`/admin/plans/${publicId}`);
      return data.data as AdminPlan;
    },
    onSuccess: () => invalidatePlanCaches(qc),
  });
}
