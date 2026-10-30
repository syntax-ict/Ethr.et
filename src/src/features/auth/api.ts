import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import axios from "axios";
import { apiClient } from "@/api/client";
import type { components, operations } from "@/api/generated";
import type { User } from "@/api/types";
import { clearApiCache } from "@/lib/api-cache";
import {
  clearOfflineIdentity,
  readOfflineIdentity,
  saveOfflineIdentity,
} from "./offline-identity";

export interface MeResponse {
  user: User;
  /** Abilities resolved server-side from the base role or custom role. */
  permissions: string[];
  /**
   * The features the organisation's plan includes, or null when nothing is
   * restricted (a trial, or no plan). The API refuses a gated action with a
   * 403; this lets the screen say so first (audit N66).
   */
  plan_features?: string[] | null;
  /**
   * Features the platform has switched off for now that this organisation
   * would otherwise have (FeatureGate::pausedFor), for the banner that tells
   * its admins (owner decision D2).
   */
  paused_features?: string[];
  /**
   * Every feature's decision for this organisation (FeatureGate::decide), so
   * a screen can tell "not in your plan" from "switched off by ETHR".
   */
  features?: Record<string, { on: boolean; reason: string }> | null;
  /** The largest upload the server accepts, in bytes (PHP's own limits). */
  upload_limit_bytes?: number;
  tenant: {
    public_id: string;
    name: string;
    subdomain: string;
    status: string;
    /** The kind of organisation sign-up recorded (`bank`, `hospital`, ...). */
    type?: string | null;
    /**
     * IANA zone the tenant displays timestamps in, e.g. `Africa/Addis_Ababa`.
     * `TenantResource` has always sent this; the type simply did not declare
     * it, so nothing on the frontend could use it. See BASELINE §12g.
     */
    timezone?: string | null;
    logo_path?: string | null;
    /** The logo for dark surfaces, when one is uploaded (the sidebar is one). */
    logo_dark_url?: string | null;
    theme?: {
      primary_color?: string;
      secondary_color?: string;
      accent_color?: string;
    } | null;
  } | null;
  /**
   * Not from the API: set when the server could not be reached and this is
   * the identity kept on the device (`offline-identity.ts`).
   */
  from_device?: boolean;
}

/**
 * No answer at all (offline, timed out) or the server failing, as opposed to
 * an answer about the session. Only these fall back to the kept identity; a
 * 401 is the server saying this browser is not signed in, and is never
 * covered up.
 */
function serverUnreachable(error: unknown): boolean {
  if (!axios.isAxiosError(error)) return false;
  return !error.response || error.response.status >= 500;
}

const meQueryOptions = {
  queryKey: ["auth", "me"],
  queryFn: async (): Promise<MeResponse> => {
    try {
      const { data } = await apiClient.get<MeResponse>("/auth/me");
      saveOfflineIdentity(data);
      return data;
    } catch (error) {
      const kept = serverUnreachable(error) ? readOfflineIdentity() : null;
      if (kept) return kept;
      throw error;
    }
  },
  retry: false,
  // The kept identity is stale at once, so the first chance to reach the
  // server (reconnecting, a remount) replaces it with the real answer.
  staleTime: (query: { state: { data?: MeResponse } }) =>
    query.state.data?.from_device ? 0 : 5 * 60 * 1000,
} as const;

export function useCurrentUser() {
  return useQuery({
    ...meQueryOptions,
    select: (data: MeResponse) => data.user,
  });
}

/**
 * The largest file the server really accepts, or null until known. Forms
 * refuse a file over it before uploading (`@/lib/uploads/upload-limit`).
 */
export function useUploadLimit(): number | null {
  const { data } = useQuery({
    ...meQueryOptions,
    select: (me: MeResponse) =>
      typeof me.upload_limit_bytes === "number" && me.upload_limit_bytes > 0
        ? me.upload_limit_bytes
        : null,
  });
  return data ?? null;
}

export function useCurrentTenant() {
  return useQuery({
    ...meQueryOptions,
    select: (data: MeResponse) => data.tenant,
  });
}

/**
 * Which plan features the organisation has. `has()` is true while loading and
 * for any feature when the plan restricts nothing, so a screen never hides a
 * paying customer's feature on a guess; the API still has the last word.
 */
export function usePlanFeatures() {
  const query = useQuery({
    ...meQueryOptions,
    select: (data: MeResponse) => data.plan_features ?? null,
  });
  const features = query.data ?? null;

  return {
    has: (feature: string): boolean =>
      features === null || features.includes(feature),
    isLoading: query.isLoading,
  };
}

/**
 * Why a feature is off for this organisation (`plan`, `platform_disabled`,
 * `revoked`, `not_in_rollout`), or null when it is on or not yet known.
 */
export function useFeatureOffReason(feature: string): string | null {
  const { data } = useQuery({
    ...meQueryOptions,
    select: (me: MeResponse) => me.features?.[feature] ?? null,
  });
  return data && !data.on ? data.reason : null;
}

/** Features the platform has switched off for now; empty while loading. */
export function usePausedFeatures(): string[] {
  const { data } = useQuery({
    ...meQueryOptions,
    select: (me: MeResponse) => me.paused_features ?? [],
  });
  return data ?? [];
}

export function useCurrentPermissions() {
  return useQuery({
    ...meQueryOptions,
    select: (data: MeResponse) => data.permissions ?? [],
  });
}

// ── Account security: two-factor setup and password change ──────

/**
 * `qr_code_url` is an `otpauth://totp/...` URI (Google2FA::getQRCodeUrl), the
 * thing a QR code encodes — not an image URL. There are no recovery codes;
 * MfaSetupController::setup() returns these two fields and nothing else.
 */
export type MfaSetup =
  operations["mfaSetup.setup"]["responses"][200]["content"]["application/json"];
/**
 * The secret from setup goes back with the first code: setup does not store
 * it, so enable is where the server first learns which secret to verify.
 */
export type EnableMfaPayload = components["schemas"]["EnableMfaRequest"];
export type DisableMfaPayload = components["schemas"]["DisableMfaRequest"];
export type ChangePasswordPayload =
  components["schemas"]["ChangePasswordRequest"];
export type ChangePasswordResult =
  operations["passwordReset.change"]["responses"][200]["content"]["application/json"];

/** A fresh, unsaved secret each call; nothing changes until `useEnableMfa`. */
export function useStartMfaSetup() {
  return useMutation({
    mutationFn: async (): Promise<MfaSetup> =>
      (await apiClient.post("/auth/mfa/setup")).data,
  });
}

/** `mfa_enabled` lives on the user, so /auth/me is refetched on success. */
export function useEnableMfa() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (payload: EnableMfaPayload) =>
      (await apiClient.post("/auth/mfa/enable", payload)).data,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["auth", "me"] });
    },
  });
}

export function useDisableMfa() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (payload: DisableMfaPayload) =>
      (await apiClient.post("/auth/mfa/disable", payload)).data,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["auth", "me"] });
    },
  });
}

/** Revokes every other session server-side, so the session list is stale. */
export function useChangePassword() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (
      payload: ChangePasswordPayload,
    ): Promise<ChangePasswordResult> =>
      (await apiClient.post("/auth/password/change", payload)).data,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["auth", "sessions"] });
    },
  });
}

export function useLogout() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async () => {
      await apiClient.post("/auth/logout");
    },
    // `onSettled`, not `onSuccess`. Signing out is a decision about this
    // device; the request is how we additionally ask the server to revoke the
    // token, and it is worth attempting, but it cannot be what decides whether
    // the local session ends.
    //
    // The app holds one QueryClient for its whole lifetime (`app/providers.tsx`
    // creates it in `useState` and never replaces it), so everything fetched
    // during the session stays in memory until this runs. Under `onSuccess`, a
    // failed request — offline, API down, token already expired — did nothing
    // at all: no clear, no navigation, and neither call site passes an
    // `onError`. The person clicked Log Out and was left on a populated
    // dashboard believing they had. For an offline-first product that is not an
    // edge case.
    onSettled: async () => {
      queryClient.clear();
      clearOfflineIdentity();
      await clearApiCache();
      window.location.href = "/login";
    },
  });
}
