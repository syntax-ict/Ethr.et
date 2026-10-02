import { apiClient } from "@/api/client";
import type { components, operations } from "@/api/generated";

/**
 * The shared kiosk terminal's two calls. They authenticate with the kiosk's
 * session token, not a user login. Shapes come from the contract, corrected
 * where Scramble cannot follow KioskSessionController::authenticate and
 * KioskCheckInController, which each `->resolve()` a resource into a plain
 * array.
 */

type AuthenticationContract =
  operations["kioskSession.authenticate"]["responses"][200]["content"]["application/json"];

/** `session` is a resolved KioskSessionResource (Scramble: `unknown[]`). */
export type KioskAuthentication = Omit<AuthenticationContract, "session"> & {
  session: components["schemas"]["KioskSessionResource"];
};

export async function authenticateKiosk(
  token: string,
): Promise<KioskAuthentication> {
  return (
    await apiClient.post<KioskAuthentication>("/kiosk/authenticate", { token })
  ).data;
}

/**
 * A resolved AttendanceRecordResource plus two keys the controller appends;
 * the contract publishes the whole body as `string`.
 */
export type KioskPunchResult =
  components["schemas"]["AttendanceRecordResource"] & {
    was_duplicate: boolean;
    employee_name: string;
  };

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
