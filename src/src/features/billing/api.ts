import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { apiClient } from "@/api/client";
import { saveBlob } from "@/lib/utils/csv-export";
import type { components, operations } from "@/api/generated";

// Re-exported: the plan catalog moved to `./plans` so the public pricing page
// can read it without importing this module and the axios client with it.
export { usePlans, type Plan } from "./plans";

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

type ChangePlanContract =
  operations["billing.changePlan"]["responses"][200]["content"]["application/json"];

/**
 * The contract's 200 also admits BillingService's `{ error }` array, but the
 * controller turns that into a 422 problem, so a 200 is always the result.
 */
export type PlanChangeResult = Exclude<ChangePlanContract, { error: string }>;

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

/**
 * Download an invoice's PDF receipt.
 *
 * Through the API client rather than `window.open`: on the single production
 * host the organisation travels in the `X-Tenant` header, which a plain
 * navigation cannot send. No tenant resolved, the invoice binding failed closed
 * and every receipt answered 404 (audit N51).
 */
export async function downloadReceipt(invoicePublicId: string): Promise<void> {
  const { data } = await apiClient.get<Blob>(
    `/billing/invoices/${invoicePublicId}/receipt`,
    { responseType: "blob" },
  );
  saveBlob(`receipt-${invoicePublicId}.pdf`, data);
}
