import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { apiClient } from "@/api/client";
import type { components, operations } from "@/api/generated";

/** `GET /settings`. */
export type TenantSettings =
  operations["settings.index"]["responses"][200]["content"]["application/json"];

/** The colours `updateBranding` writes into `theme`. */
export type BrandColorKey = keyof TenantSettings["branding"]["theme"];

export type SsoSettings = TenantSettings["sso"];

/**
 * `PUT /settings` merges `settings` into the tenant's settings JSON
 * (`array_merge` in `SettingsController::update`). Every key it accepts is in
 * the contract; any other is a 422 (audit N6).
 */
export type SettingsUpdate =
  components["schemas"]["UpdateSettingsRequest"]["settings"];

export type SsoUpdate = components["schemas"]["UpdateSsoRequest"];
export type OrganizationUpdate =
  components["schemas"]["UpdateOrganizationRequest"];
export type BrandingUpdate = components["schemas"]["UpdateBrandingRequest"];

export type ScimToken =
  operations["settings.generateScimToken"]["responses"][201]["content"]["application/json"];

export type NotificationTemplate =
  operations["notificationTemplate.index"]["responses"][200]["content"]["application/json"]["templates"][number];
export type NotificationTemplateUpdate =
  components["schemas"]["UpdateNotificationTemplateRequest"];

/**
 * Every tenant-settings query sits under ["settings"]. The organization name
 * and the branding theme are also rendered from /auth/me (sidebar, header,
 * `TenantBrandingProvider`), so writes to those refresh it too.
 */
const keys = {
  all: ["settings"] as const,
  notificationTemplates: ["settings", "notification-templates"] as const,
};
const AUTH_ME = ["auth", "me"] as const;

// ── Queries ───────────────────────────────────────────────────────────────────

export function useSettings() {
  return useQuery<TenantSettings>({
    queryKey: keys.all,
    queryFn: async () => (await apiClient.get("/settings")).data,
  });
}

export function useNotificationTemplates() {
  return useQuery<NotificationTemplate[]>({
    queryKey: keys.notificationTemplates,
    queryFn: async () =>
      (await apiClient.get("/settings/notification-templates")).data.templates,
  });
}

// ── Writes ────────────────────────────────────────────────────────────────────
// Toasts stay with the caller, passed as `mutate(vars, { onSuccess, onError })`.

function useSettingsMutation<TVars, TData>(
  mutationFn: (vars: TVars) => Promise<TData>,
  alsoInvalidate: readonly (readonly string[])[] = [],
) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: keys.all });
      for (const queryKey of alsoInvalidate) {
        queryClient.invalidateQueries({ queryKey });
      }
    },
  });
}

export function useUpdateSettings() {
  return useSettingsMutation(
    async (settings: SettingsUpdate) =>
      (await apiClient.put("/settings", { settings })).data,
    // `/auth/me` carries the calendar in force, which falls back to the
    // organisation's: an admin who has not chosen their own sees the change.
    [["auth", "me"]],
  );
}

export function useUpdateSso() {
  return useSettingsMutation(
    async (payload: SsoUpdate) =>
      (await apiClient.put("/settings/sso", payload)).data,
  );
}

export function useUpdateOrganization() {
  return useSettingsMutation(
    async (payload: OrganizationUpdate) =>
      (await apiClient.put("/settings/organization", payload)).data,
    [AUTH_ME],
  );
}

export function useUpdateBranding() {
  return useSettingsMutation(
    async (payload: BrandingUpdate) =>
      (await apiClient.put("/settings/branding", payload)).data,
    [AUTH_ME],
  );
}

/** The uploaded logo and the logo now in effect, after an upload or removal. */
export interface LogoUploadResult {
  uploaded_logo_url: string | null;
  logo_url: string | null;
}

/**
 * Upload the organisation's logo (PNG, JPEG or WebP; the server re-encodes it).
 * An uploaded logo is shown in preference to a linked one, everywhere: the
 * sign-in page, the sidebar and header, the kiosk.
 */
export function useUploadLogo() {
  return useSettingsMutation(
    async (file: File): Promise<LogoUploadResult> => {
      const body = new FormData();
      body.append("logo", file);
      return (
        await apiClient.post("/settings/branding/logo", body, {
          headers: { "Content-Type": "multipart/form-data" },
        })
      ).data;
    },
    [AUTH_ME],
  );
}

/** Remove the uploaded logo; a linked logo, if any, takes over. */
export function useRemoveLogo() {
  return useSettingsMutation(
    async (): Promise<LogoUploadResult> =>
      (await apiClient.delete("/settings/branding/logo")).data,
    [AUTH_ME],
  );
}

/** The organisation's other brand images, as `GET /settings` lists them. */
export type BrandAssets = TenantSettings["branding"]["assets"];
export type BrandAssetKind = keyof BrandAssets;

/**
 * Upload one of the other brand images (a logo for dark backgrounds, a
 * square mark, a cover photo). The server re-encodes it and serves it at a
 * URL that changes with every upload; the response lists every asset.
 */
export function useUploadBrandAsset(kind: BrandAssetKind) {
  return useSettingsMutation(
    async (file: File): Promise<{ assets: BrandAssets }> => {
      const body = new FormData();
      body.append("image", file);
      return (
        await apiClient.post(`/settings/branding/assets/${kind}`, body, {
          headers: { "Content-Type": "multipart/form-data" },
        })
      ).data;
    },
    [],
  );
}

export function useRemoveBrandAsset(kind: BrandAssetKind) {
  return useSettingsMutation(
    async (): Promise<{ assets: BrandAssets }> =>
      (await apiClient.delete(`/settings/branding/assets/${kind}`)).data,
    [],
  );
}

/** Where in the cover to keep in frame, as fractions of its width and height. */
export function useUpdateCoverFocal() {
  return useSettingsMutation(
    async (focal: { x: number; y: number }): Promise<{ assets: BrandAssets }> =>
      (await apiClient.put("/settings/branding/assets/cover/focal", focal))
        .data,
    [],
  );
}

export type TenantLandingSettings = TenantSettings["landing"];
export type LandingUpdate = components["schemas"]["UpdateLandingRequest"];

/** The organisation's sign-in page: its words, help contacts and background. */
export function useUpdateLanding() {
  return useSettingsMutation(
    async (payload: LandingUpdate) =>
      (await apiClient.put<TenantLandingSettings>("/settings/landing", payload))
        .data,
    [],
  );
}

export function useUpdateNotificationTemplate() {
  return useSettingsMutation(
    async (vars: {
      type: NotificationTemplate["type"];
      payload: NotificationTemplateUpdate;
    }) =>
      (
        await apiClient.put(
          `/settings/notification-templates/${vars.type}`,
          vars.payload,
        )
      ).data,
  );
}

/**
 * Issue a SCIM bearer token. The plain token is in this response and nowhere
 * else — the server keeps only its hash — so the caller shows it once and must
 * not cache it. The token is an API key with the `scim` ability, so it appears
 * in (and is revoked from) the API-keys list, which is refreshed here.
 */
export function useGenerateScimToken() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (name: string): Promise<ScimToken> =>
      (await apiClient.post("/settings/scim-token", { name })).data,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["api-keys"] });
    },
    // A mutation keeps its last result in the cache; a secret has no business
    // outliving the screen that displayed it.
    gcTime: 0,
  });
}

// ── Address: how the organisation is reached ─────────────────────────────────

export type TenantAddress = TenantSettings["address"];
export type AddressUpdate = components["schemas"]["UpdateAddressRequest"];
export type AddressResult =
  operations["settings.updateAddress"]["responses"][200]["content"]["application/json"];

/**
 * Choose `path`, `subdomain` or `custom_domain`, optionally assigning or
 * clearing (`null`) the domain in the same save. The server normalises the
 * domain and owns every rule; a 422 on `custom_domain` is shown under the
 * field.
 */
export function useUpdateAddress() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: async (payload: AddressUpdate) =>
      (await apiClient.put<AddressResult>("/settings/address", payload)).data,
    onSuccess: () => queryClient.invalidateQueries({ queryKey: keys.all }),
  });
}

/**
 * Checks the pending domain's TXT token and CNAME. A 422 carries one message
 * per record that was not found, under `errors.txt` and `errors.cname`.
 */
export function useVerifyAddress() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: async () =>
      (await apiClient.post<AddressResult>("/settings/address/verify")).data,
    onSuccess: () => queryClient.invalidateQueries({ queryKey: keys.all }),
  });
}
