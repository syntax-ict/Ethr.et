import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { apiClient } from "@/api/client";
import type { components, operations } from "@/api/generated";

// Shapes come from the generated contract, so a renamed field fails tsc here
// instead of rendering blank.

export type BillingDashboard =
  operations["billing.dashboard"]["responses"][200]["content"]["application/json"];
export type BillingInvoice = BillingDashboard["invoices"][number];

/**
 * Where tenants pay ETHR. Set by the super admin; the dashboard carries null
 * while the platform operator has not finished configuring the account.
 */
export type PaymentDetails = NonNullable<BillingDashboard["payment_details"]>;

/**
 * The public plan catalog row, exactly as `GET /api/v1/plans` sends it.
 *
 * `PlanResource` exists so the contract stops promising `is_active`,
 * `created_at` and `updated_at` that the endpoint never carried. The admin-only
 * fields live on `AdminPlan` in `features/admin/api.ts`.
 */
export type Plan = components["schemas"]["PlanResource"];

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

type ChangePlanContract =
  operations["billing.changePlan"]["responses"][200]["content"]["application/json"];

/**
 * The contract's 200 also admits BillingService's `{ error }` array, but the
 * controller turns that into a 422 problem, so a 200 is always the result.
 * `proration_cents` is an integer difference that Scramble types as `string`.
 */
export type PlanChangeResult = Omit<
  Exclude<ChangePlanContract, { error: string }>,
  "proration_cents"
> & { proration_cents: number };

export function useChangePlan() {
  const queryClient = useQueryClient();

  return useMutation<
    PlanChangeResult,
    unknown,
    components["schemas"]["ChangePlanRequest"]
  >({
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
