import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { apiClient } from "@/api/client";
import type { components, operations } from "@/api/generated";

export type Webhook =
  operations["webhook.index"]["responses"][200]["content"]["application/json"]["webhooks"][number];
export type CreatedWebhook =
  operations["webhook.store"]["responses"][201]["content"]["application/json"];
export type WebhookPayload = components["schemas"]["StoreWebhookRequest"];
export type WebhookDelivery =
  operations["webhook.deliveries"]["responses"][200]["content"]["application/json"]["deliveries"][number];
export type WebhookTestResult =
  operations["webhook.test"]["responses"][200]["content"]["application/json"];

/**
 * Exactly the events the API dispatches — every `$this->webhook(...)` call
 * site (`DispatchesWebhooks`): EmployeeController, LeaveRequestController,
 * PayrollController and ProcessPayrollJob — and the list the server accepts,
 * `App\Support\WebhookEvents::ALL` (`events.*` is validated against it, audit
 * N11). Add an event there, with the call site that sends it, and here.
 */
export const WEBHOOK_EVENTS = [
  "employee.created",
  "employee.updated",
  "leave.requested",
  "leave.approved",
  "leave.rejected",
  "payroll.processed",
  "payroll.approved",
  "payroll.voided",
  "payroll.reprocessed",
] as const;

export type WebhookEvent = (typeof WEBHOOK_EVENTS)[number];

const keys = {
  all: ["webhooks"] as const,
  deliveries: (publicId: string) =>
    ["webhooks", "deliveries", publicId] as const,
};

/** `GET /webhooks` is not paginated. */
export function useWebhooks() {
  return useQuery<Webhook[]>({
    queryKey: keys.all,
    queryFn: async () => (await apiClient.get("/webhooks")).data.webhooks,
  });
}

/** The 50 most recent delivery attempts for one webhook. */
export function useWebhookDeliveries(
  publicId: string | null,
  options?: { enabled?: boolean },
) {
  return useQuery<WebhookDelivery[]>({
    queryKey: keys.deliveries(publicId ?? ""),
    queryFn: async () =>
      (await apiClient.get(`/webhooks/${publicId}/deliveries`)).data.deliveries,
    enabled: !!publicId && (options?.enabled ?? true),
  });
}

/**
 * The response carries the signing `secret` exactly once; the list never
 * returns it.
 */
export function useCreateWebhook() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (payload: WebhookPayload): Promise<CreatedWebhook> =>
      (await apiClient.post("/webhooks", payload)).data,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: keys.all });
    },
    // A mutation keeps its last result in the cache; the secret has no
    // business outliving the banner that displayed it.
    gcTime: 0,
  });
}

export function useDeleteWebhook() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (publicId: string): Promise<void> => {
      await apiClient.delete(`/webhooks/${publicId}`);
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: keys.all });
    },
  });
}

/** Queue a `test` event; it appears in the delivery history. */
export function useTestWebhook() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (publicId: string): Promise<WebhookTestResult> =>
      (await apiClient.post(`/webhooks/${publicId}/test`)).data,
    onSuccess: (_data, publicId) => {
      queryClient.invalidateQueries({ queryKey: keys.deliveries(publicId) });
    },
  });
}
