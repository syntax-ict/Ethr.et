import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { apiClient } from "@/api/client";

export interface BillingInvoice {
  public_id: string;
  total_cents: number;
  status: string;
  due_date: string | null;
  paid_at: string | null;
}

/** Where tenants pay ETHR. Set by the super admin, null until configured. */
export interface PaymentDetails {
  bank_name: string;
  account_number: string;
  account_name: string;
  instructions: string | null;
  instructions_am: string | null;
}

export interface BillingDashboard {
  plan: string | null;
  plan_price_cents: number | null;
  subscription_status: string | null;
  current_period_end: string | null;
  invoices: BillingInvoice[];
  tenant_status: string;
  trial_ends_at: string | null;
  trial_days_remaining: number | null;
  /** Null while the platform operator has not finished configuring the account. */
  payment_details: PaymentDetails | null;
}

/**
 * The public plan catalog row, exactly as `GET /api/v1/plans` sends it.
 *
 * Mirrors `PlanResource`, which exists so the generated contract stops
 * promising `is_active`, `created_at` and `updated_at` that the endpoint never
 * carried. Anything absent from that resource is absent here on purpose — the
 * admin-only fields live on `AdminPlan` in `features/admin/api.ts`.
 */
export interface Plan {
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
  is_popular: boolean;
  sort_order: number;
}

export function useBillingDashboard() {
  return useQuery<BillingDashboard>({
    queryKey: ["billing", "dashboard"],
    queryFn: async () => {
      const { data } = await apiClient.get("/billing/dashboard");
      return data;
    },
    staleTime: 30 * 60 * 1000,
  });
}

/**
 * `options.initialData` exists for the public pricing page, which is a
 * prerendered static route: without a seed value its HTML would ship with no
 * prices, so a crawler and every link preview would see an empty pricing table.
 * It passes the committed build-time snapshot, and the live catalog replaces it
 * when the fetch resolves. Authenticated callers pass nothing and are unchanged.
 */
export function usePlans(options?: {
  initialData?: { data: Plan[] };
  /**
   * When `initialData` was produced, as epoch ms. Required alongside it:
   * without it TanStack treats the seed as fetched now, and `staleTime` below
   * would then suppress the refetch entirely — the caller would render its seed
   * forever and never see the live catalog.
   */
  initialDataUpdatedAt?: number;
}) {
  return useQuery<{ data: Plan[] }>({
    queryKey: ["plans"],
    queryFn: async () => {
      const { data } = await apiClient.get("/plans");
      return data;
    },
    staleTime: 30 * 60 * 1000,
    initialData: options?.initialData,
    initialDataUpdatedAt: options?.initialDataUpdatedAt,
  });
}

export interface PlanChangeResult {
  old_plan: string;
  new_plan: string;
  proration_cents: number;
  effective_immediately: boolean;
}

export function useChangePlan() {
  const queryClient = useQueryClient();

  return useMutation<PlanChangeResult, unknown, { plan_public_id: string }>({
    mutationFn: async (payload) => {
      const { data } = await apiClient.post("/billing/change-plan", payload);
      return data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["billing"] });
    },
  });
}

export function useMarkInvoicePaid() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (invoicePublicId: string) => {
      const { data } = await apiClient.put(
        `/billing/invoices/${invoicePublicId}/mark-paid`,
      );
      return data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["billing"] });
    },
  });
}
