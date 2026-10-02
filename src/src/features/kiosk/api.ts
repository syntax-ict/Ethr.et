import { apiClient } from "@/api/client";
import type { components, operations } from "@/api/generated";

/**
 * The shared kiosk terminal's two calls. They authenticate with the kiosk's
 * session token, not a user login. Shapes come from the contract.
 */

export type KioskAuthentication =
  operations["kioskSession.authenticate"]["responses"][200]["content"]["application/json"];

export async function authenticateKiosk(
  token: string,
): Promise<KioskAuthentication> {
  return (
    await apiClient.post<KioskAuthentication>("/kiosk/authenticate", { token })
  ).data;
}

/** The punched record, whether it was a replay, and whose punch it was. */
export type KioskPunchResult = components["schemas"]["AttendancePunchResource"];

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
