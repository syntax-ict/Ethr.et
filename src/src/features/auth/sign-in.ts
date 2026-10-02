import { apiClient } from "@/api/client";
import type { components } from "@/api/generated";

/**
 * The sign-in, registration and impersonation-claim calls.
 *
 * Plain async functions rather than TanStack hooks: each is one step in an
 * imperative sequence (CSRF cookie → credentials → branch on MFA), not cached
 * server state. Request bodies come from the generated contract; responses are
 * typed to the fields the screens read, from the controllers named.
 */

type Schemas = components["schemas"];

/** Sanctum's CSRF cookie, served from the API origin root, not /api/v1. */
export async function prepareCsrfCookie(): Promise<void> {
  await apiClient.get("/sanctum/csrf-cookie", { baseURL: "" });
}

/** LoginController: `mfa_required` when a second factor is still owed. */
export interface LoginResult {
  mfa_required?: boolean;
}

export async function login(
  payload: Schemas["LoginRequest"],
): Promise<LoginResult> {
  return (await apiClient.post<LoginResult>("/auth/login", payload)).data;
}

export async function verifyMfa(
  payload: Schemas["VerifyMfaRequest"],
): Promise<void> {
  await apiClient.post("/auth/mfa/verify", payload);
}

/** OtpController::request — `message` is shown as-is. */
export async function requestOtp(
  payload: Schemas["RequestOtpRequest"],
): Promise<{ message: string }> {
  return (
    await apiClient.post<{ message: string }>("/auth/otp/request", payload)
  ).data;
}

/** OtpController::verify. */
export async function verifyOtp(
  payload: Schemas["VerifyOtpRequest"],
): Promise<LoginResult> {
  return (await apiClient.post<LoginResult>("/auth/otp/verify", payload)).data;
}

export async function registerTenant(
  payload: Schemas["RegisterTenantRequest"],
): Promise<void> {
  await apiClient.post("/auth/register", payload);
}

/** SubdomainCheckController — advice only; registration enforces. */
export async function checkSubdomain(subdomain: string): Promise<boolean> {
  const { data } = await apiClient.get<{ available: boolean }>(
    "/register/check-subdomain",
    { params: { subdomain } },
  );
  return data.available;
}

/** SsoController::initiate — the IdP URL to leave the app for. */
export async function initiateSso(tenant: string): Promise<string> {
  const { data } = await apiClient.get<{ redirect_url: string }>(
    `/sso/saml/${encodeURIComponent(tenant)}/initiate`,
  );
  return data.redirect_url;
}

/**
 * The tenant-host half of the impersonation handoff.
 *
 * The CSRF cookie is fetched first, exactly as the login form does. The claim
 * is the browser's first request to this host — it arrives from the platform
 * host by full navigation — so it holds no `XSRF-TOKEN` cookie yet, and the
 * claim is a stateful POST, which Sanctum refuses with a 419 without one. The
 * test suite cannot see this (Laravel skips CSRF verification under tests), so
 * the order is pinned on this side instead.
 */
export async function claimImpersonationSession(nonce: string): Promise<void> {
  await prepareCsrfCookie();
  await apiClient.post("/auth/session/claim", { nonce });
}
