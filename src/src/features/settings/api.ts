import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { apiClient } from "@/api/client";
import type { components, operations } from "@/api/generated";

type SettingsContract =
  operations["settings.index"]["responses"][200]["content"]["application/json"];

export type BrandColorKey =
  "primary_color" | "secondary_color" | "accent_color";

/**
 * `GET /settings`, from the generated contract except where Scramble guessed.
 *
 * Every `$tenant->settings['x'] ?? <default>` is published as
 * `string | <default literal>` and `$tenant->theme ?? []` as `string | string[]`.
 * Those groups mirror `SettingsController::index` instead: the settings JSON
 * stores numbers where the defaults are numbers, `logo_url` is the nullable
 * `tenants.logo_path`, and `theme` is the color map `updateBranding` writes.
 */
export type TenantSettings = Omit<
  SettingsContract,
  "branding" | "leave" | "payroll" | "security"
> & {
  branding: {
    logo_url: string | null;
    theme: Partial<Record<BrandColorKey, string>>;
  };
  leave: { working_days: number[] };
  payroll: {
    pay_period: string;
    run_day: number;
    fiscal_year_start_month: number;
    pagumen_proration_strategy: string;
    retirement_age: number;
  };
  security: {
    mfa_policy: string;
    session_timeout_minutes: number;
  };
};

export type SsoSettings = TenantSettings["sso"];

/**
 * `PUT /settings` merges `settings` into the tenant's settings JSON
 * (`array_merge` in `SettingsController::update`). The contract types only the
 * three keys the FormRequest validates; the others here are keys `index` reads
 * back and this app writes, which the server accepts unvalidated.
 */
export type SettingsUpdate =
  components["schemas"]["UpdateSettingsRequest"]["settings"] & {
    mfa_policy?: string;
    session_timeout_minutes?: number;
    run_day?: number;
  };

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
