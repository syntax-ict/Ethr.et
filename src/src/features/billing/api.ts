import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { apiClient } from "@/api/client";

export interface BillingInvoice {
  public_id: string;
  total_cents: number;
  status: string;
  due_date: string | null;
  paid_at: string | null;
}

export interface BillingDashboard {
  plan: string | null;
  plan_price_cents: number | null;
  subscription_status: string | null;
  current_period_end: string | null;
  invoices: BillingInvoice[];
}

export interface Plan {
  public_id: string;
  name: string;
  slug: string;
  price_cents: number;
  max_employees: number | null;
  max_branches: number | null;
  max_devices: number | null;
  features: string[] | null;
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

export function usePlans() {
  return useQuery<{ data: Plan[] }>({
    queryKey: ["plans"],
    queryFn: async () => {
      const { data } = await apiClient.get("/plans");
      return data;
    },
    staleTime: 30 * 60 * 1000,
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
