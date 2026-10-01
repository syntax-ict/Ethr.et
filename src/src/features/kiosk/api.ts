import { apiClient } from "@/api/client";
import type { components } from "@/api/generated";

/**
 * The shared kiosk terminal's two calls. They authenticate with the kiosk's
 * session token, not a user login. Responses are typed to the fields the
 * terminal reads, from KioskSessionController::authenticate and
 * KioskCheckInController.
 */

export interface KioskAuthentication {
  session: { branch?: { name: string } | null };
  tenant: { name: string; subdomain: string; logo_path: string | null };
  settings: { pin_required: boolean; auto_reset_seconds: number };
}

export async function authenticateKiosk(
  token: string,
): Promise<KioskAuthentication> {
  return (
    await apiClient.post<KioskAuthentication>("/kiosk/authenticate", { token })
  ).data;
}

export interface KioskPunchResult {
  employee_name?: string;
  employee?: { name: string };
}

export async function kioskPunch(
  token: string,
  payload: components["schemas"]["KioskCheckInRequest"],
): Promise<KioskPunchResult> {
  return (
    await apiClient.post<KioskPunchResult>("/kiosk/check-in", payload, {
      headers: { "X-Kiosk-Token": token },
    })
  ).data;
}
