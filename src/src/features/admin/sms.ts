import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import type { components } from "@/api/generated";
import { apiClient } from "@/api/client";
import { usePermissions } from "@/lib/hooks/usePermissions";

/**
 * SMS delivery in the platform console: up to two HTTP gateways, a route for
 * one network, the on/off switch and the daily cap (SmsDeliveryController).
 *
 * A gateway's credential is write-only. The server reports `secret_set` and
 * never the value, so the form sends `auth_secret` only when it is replaced.
 */
export type SmsSlot = "primary" | "secondary";

export type SaveSmsGatewayPayload =
  components["schemas"]["SaveSmsGatewayRequest"];

export interface SmsGatewayState {
  slot: SmsSlot;
  label: string | null;
  endpoint: string;
  method: "form" | "json" | "get";
  auth_type: "none" | "header" | "bearer" | "fields" | "basic";
  auth_header: string | null;
  auth_username: string | null;
  secret_set: boolean;
  username_field: string | null;
  password_field: string | null;
  recipient_field: string;
  message_field: string;
  sender_field: string | null;
  sender_id: string | null;
  number_format: "e164" | "international" | "local";
  success_json_path: string | null;
  success_json_value: string | null;
  success_body_contains: string | null;
  timeout_seconds: number;
  verified: boolean;
  verified_at: string | null;
  last_test_at: string | null;
  last_test_ok: boolean | null;
  last_test_status: number | null;
  last_test_response: string | null;
}

export interface SmsDeliveryState {
  enabled: boolean;
  /** `7` sends Safaricom numbers to the secondary gateway, `9` Ethio Telecom. */
  route_prefix: "7" | "9" | null;
  daily_cap: number;
  sent_today: number;
  /** Invitations go by text to people with only a phone. Off until SMS is live. */
  invitations_enabled: boolean;
  /** Percent of the daily cap invitations may use; sign-in codes keep the rest. */
  invitation_share: number;
  invitations_sent_today: number;
  available: boolean;
  /** Where the live configuration comes from: this page, `.env`, or nowhere. */
  source: "console" | "env" | "none";
  primary: SmsGatewayState | null;
  secondary: SmsGatewayState | null;
}

export interface SmsTestResult {
  ok: boolean;
  status: number | null;
  response: string;
  verified: boolean;
}

const KEY = ["admin", "sms"] as const;

export function useSmsDelivery() {
  const { isSuperAdmin } = usePermissions();

  return useQuery<SmsDeliveryState>({
    queryKey: KEY,
    queryFn: async () => (await apiClient.get("/admin/sms")).data,
    enabled: isSuperAdmin,
  });
}

function useSmsMutation<TVariables, TResult = SmsDeliveryState>(
  run: (variables: TVariables) => Promise<TResult>,
) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: run,
    onSuccess: (data) => {
      if (data && typeof data === "object" && "source" in data) {
        qc.setQueryData(KEY, data);
      } else {
        void qc.invalidateQueries({ queryKey: KEY });
      }
    },
  });
}

export function useUpdateSmsSettings() {
  return useSmsMutation(
    async (
      payload: Partial<
        Pick<
          SmsDeliveryState,
          | "enabled"
          | "route_prefix"
          | "daily_cap"
          | "invitations_enabled"
          | "invitation_share"
        >
      >,
    ) =>
      (await apiClient.put<SmsDeliveryState>("/admin/sms/settings", payload))
        .data,
  );
}

export function useSaveSmsGateway() {
  return useSmsMutation(
    async ({
      slot,
      payload,
    }: {
      slot: SmsSlot;
      payload: SaveSmsGatewayPayload;
    }) =>
      (
        await apiClient.put<SmsDeliveryState>(
          `/admin/sms/gateways/${slot}`,
          payload,
        )
      ).data,
  );
}

export function useRemoveSmsGateway() {
  return useSmsMutation(
    async (slot: SmsSlot) =>
      (await apiClient.delete<SmsDeliveryState>(`/admin/sms/gateways/${slot}`))
        .data,
  );
}

export function useTestSmsGateway() {
  return useSmsMutation<{ slot: SmsSlot; phone: string }, SmsTestResult>(
    async ({ slot, phone }) =>
      (
        await apiClient.post<SmsTestResult>(
          `/admin/sms/gateways/${slot}/test`,
          { phone },
        )
      ).data,
  );
}
