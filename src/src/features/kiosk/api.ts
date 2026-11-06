import { apiClient } from "@/api/client";
import type { components, operations } from "@/api/generated";

/**
 * The shared kiosk terminal's calls. They authenticate with the kiosk's
 * session token, not a user login. Shapes come from the contract.
 *
 * All of them opt out of the client's session refresh: the terminal has no session,
 * and a 401 here is the API saying "invalid token" or "wrong PIN", which the
 * kiosk screen shows. Without the opt-out it redirected to /login (N48).
 */

export type KioskAuthentication =
  operations["kioskSession.authenticate"]["responses"][200]["content"]["application/json"];

export async function authenticateKiosk(
  token: string,
): Promise<KioskAuthentication> {
  return (
    await apiClient.post<KioskAuthentication>(
      "/kiosk/authenticate",
      { token },
      { skipAuthRefresh: true },
    )
  ).data;
}

/**
 * Leave kiosk mode, if `adminPin` is the PIN the kiosk was registered with.
 * Resolves on 204. A wrong PIN rejects with a 422 naming `admin_pin`; a token
 * that was deactivated or regenerated rejects with a 401.
 */
export async function verifyKioskExit(
  token: string,
  adminPin: string,
): Promise<void> {
  await apiClient.post(
    "/kiosk/exit",
    { token, admin_pin: adminPin },
    { skipAuthRefresh: true },
  );
}

/** The punch, whether it was a replay, and who it was for (name, title, face). */
export type KioskPunchResult = components["schemas"]["KioskPunchResource"];

export async function kioskPunch(
  token: string,
  payload: components["schemas"]["KioskCheckInRequest"],
): Promise<KioskPunchResult> {
  return (
    await apiClient.post<KioskPunchResult>("/kiosk/check-in", payload, {
      headers: { "X-Kiosk-Token": token },
      skipAuthRefresh: true,
    })
  ).data;
}

export type KioskQr =
  operations["kiosk.kioskQr"]["responses"][200]["content"]["application/json"];

/**
 * The code to show on this kiosk's screen now. A new one every call; each
 * lives `ttl_seconds` and is accepted from one scan. Rejects with 403 where
 * QR is switched off for this kiosk's branch.
 */
export async function fetchKioskQr(token: string): Promise<KioskQr> {
  return (
    await apiClient.post<KioskQr>(
      "/kiosk/qr",
      {},
      { headers: { "X-Kiosk-Token": token }, skipAuthRefresh: true },
    )
  ).data;
}

/**
 * Put a punch just made at this kiosk in front of HR, because the person on
 * the confirmation screen is not the one who punched. It never undoes the
 * punch, and the server accepts it only for two minutes after.
 */
export async function reportKioskPunch(
  token: string,
  recordId: string,
  punch: "check_in" | "check_out",
): Promise<void> {
  await apiClient.post(
    `/kiosk/punches/${encodeURIComponent(recordId)}/not-me`,
    { punch },
    { headers: { "X-Kiosk-Token": token }, skipAuthRefresh: true },
  );
}

export type KioskSelfServiceStart =
  operations["kioskSelfService.start"]["responses"][200]["content"]["application/json"];

export type KioskSelfServiceSummary =
  operations["kioskSelfService.show"]["responses"][200]["content"]["application/json"];

/**
 * Self-service with a code and the person's own PIN. Resolves with a short
 * pass bound to this kiosk; rejects with a 401 for a code and PIN that do
 * not match, 422 for someone with no PIN yet, 429 while they are locked out.
 */
export async function startKioskSelfService(
  token: string,
  employeeCode: string,
  pin: string,
): Promise<KioskSelfServiceStart> {
  return (
    await apiClient.post<KioskSelfServiceStart>(
      "/kiosk/self-service",
      { employee_code: employeeCode, pin },
      { headers: { "X-Kiosk-Token": token }, skipAuthRefresh: true },
    )
  ).data;
}

export async function kioskSelfService(
  token: string,
  pass: string,
): Promise<KioskSelfServiceSummary> {
  return (
    await apiClient.get<KioskSelfServiceSummary>("/kiosk/self-service", {
      headers: { "X-Kiosk-Token": token, "X-Kiosk-Pass": pass },
      skipAuthRefresh: true,
    })
  ).data;
}

/** Ends the pass now. Failing to reach the server is harmless: it expires. */
export async function endKioskSelfService(
  token: string,
  pass: string,
): Promise<void> {
  await apiClient
    .delete("/kiosk/self-service", {
      headers: { "X-Kiosk-Token": token, "X-Kiosk-Pass": pass },
      skipAuthRefresh: true,
    })
    .catch(() => {});
}

export async function changeKioskPin(
  token: string,
  pass: string,
  body: components["schemas"]["ChangeKioskPinRequest"],
): Promise<void> {
  await apiClient.put("/kiosk/self-service/pin", body, {
    headers: { "X-Kiosk-Token": token, "X-Kiosk-Pass": pass },
    skipAuthRefresh: true,
  });
}
