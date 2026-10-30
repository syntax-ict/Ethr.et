import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import type { components } from "@/api/generated";
import { apiClient } from "@/api/client";
import { usePermissions } from "@/lib/hooks/usePermissions";

/**
 * The platform feature switches (AdminFeatureController and the feature
 * endpoints of AdminTenantController; docs/FEATURE-CONTROL-CENTER-DESIGN.md).
 *
 * A switch takes effect on the organisation's next request. Every change
 * takes a reason, which goes into the audit log with the admin's name.
 */
export type PlanFeatureKey = components["schemas"]["PlanFeature"];

/** The catalogue, `App\Enums\Feature`: the plan features, then what no plan decides. */
export type FeatureKey =
  | PlanFeatureKey
  | "webhooks_outbound"
  | "web_push"
  | "email_sign_in_codes"
  | "attendance_selfies"
  | "kiosk_check_in"
  | "self_registration"
  | "scim"
  | "device_webhooks_inbound"
  | "public_news"
  | "public_vacancies";
export type FeatureKind = "module" | "integration" | "public";
export type FeatureState = "enabled" | "hidden" | "restricted" | "disabled";
export type FeatureOverrideMode = "grant" | "revoke";
export type FeatureReason =
  | "enabled"
  | "granted"
  | "platform_disabled"
  | "revoked"
  | "not_in_rollout"
  | "plan";

/**
 * English fallbacks for each feature's name, shared with the plans page. A
 * plan feature is named under `admin_plans.feature.<key>`, the rest under
 * `admin_features.name.<key>`; {@link featureLabelKey} picks.
 */
export const FEATURE_LABEL_FALLBACK: Record<FeatureKey, string> = {
  employee_management: "Employee management",
  attendance: "Attendance",
  leave: "Leave",
  notifications: "Notifications",
  payroll: "Payroll",
  reports: "Reports",
  custom_reports: "Custom reports",
  audit_log: "Audit log",
  api_access: "API access",
  webhooks: "Webhooks",
  custom_domain: "Custom domain (add-on)",
  webhooks_outbound: "Webhook delivery",
  web_push: "Phone notifications",
  email_sign_in_codes: "Sign-in codes by email",
  attendance_selfies: "Attendance selfies",
  kiosk_check_in: "Kiosk check-in",
  self_registration: "New organisation sign-up",
  scim: "SCIM provisioning",
  device_webhooks_inbound: "Attendance terminals",
  public_news: "Public news page",
  public_vacancies: "Public vacancies",
};

const PLAN_FEATURES = new Set<string>([
  "employee_management",
  "attendance",
  "leave",
  "notifications",
  "payroll",
  "reports",
  "custom_reports",
  "audit_log",
  "api_access",
  "webhooks",
  "custom_domain",
] satisfies PlanFeatureKey[]);

/** The translation key for a feature's name. */
export function featureLabelKey(key: FeatureKey): string {
  return PLAN_FEATURES.has(key)
    ? `admin_plans.feature.${key}`
    : `admin_features.name.${key}`;
}

export interface PlatformFeature {
  key: FeatureKey;
  kind: FeatureKind;
  /** False when only the platform state decides it: no grant, no rollout. */
  overridable: boolean;
  state: FeatureState;
  default_state: FeatureState;
  reason: string | null;
  changed_at: string | null;
  grants: number;
  revokes: number;
}

export interface TenantFeatureOverride {
  mode: FeatureOverrideMode;
  reason: string;
  expires_at: string | null;
  expired: boolean;
  changed_at: string | null;
}

export interface TenantFeature {
  key: FeatureKey;
  kind: FeatureKind;
  overridable: boolean;
  on: boolean;
  visible: boolean;
  reason: FeatureReason;
  override: TenantFeatureOverride | null;
}

export type UpdateFeatureStatePayload =
  components["schemas"]["UpdateFeatureStateRequest"];
export type SetTenantFeatureOverridePayload =
  components["schemas"]["UpdateTenantFeatureOverrideRequest"];

const KEY = ["admin", "features"] as const;

const tenantKey = (publicId: string) =>
  ["admin", "tenants", publicId, "features"] as const;

export function usePlatformFeatures() {
  const { isSuperAdmin } = usePermissions();

  return useQuery<PlatformFeature[]>({
    queryKey: KEY,
    queryFn: async () =>
      (await apiClient.get<{ data: PlatformFeature[] }>("/admin/features")).data
        .data,
    enabled: isSuperAdmin,
  });
}

export function useUpdateFeatureState() {
  const qc = useQueryClient();

  return useMutation({
    mutationFn: async ({
      key,
      ...payload
    }: UpdateFeatureStatePayload & { key: FeatureKey }) =>
      (await apiClient.put<PlatformFeature>(`/admin/features/${key}`, payload))
        .data,
    onSuccess: (feature) => {
      qc.setQueryData<PlatformFeature[]>(KEY, (current) =>
        current?.map((row) => (row.key === feature.key ? feature : row)),
      );
      qc.invalidateQueries({ queryKey: ["admin", "tenants"] });
    },
  });
}

export function useTenantFeatures(publicId: string) {
  const { isSuperAdmin } = usePermissions();

  return useQuery<TenantFeature[]>({
    queryKey: tenantKey(publicId),
    queryFn: async () =>
      (
        await apiClient.get<{ data: TenantFeature[] }>(
          `/admin/tenants/${publicId}/features`,
        )
      ).data.data,
    enabled: isSuperAdmin && publicId !== "",
  });
}

function useTenantFeatureMutation<TVariables extends { key: FeatureKey }>(
  publicId: string,
  run: (variables: TVariables) => Promise<{ data: TenantFeature[] }>,
) {
  const qc = useQueryClient();

  return useMutation({
    mutationFn: run,
    onSuccess: (response) => {
      qc.setQueryData(tenantKey(publicId), response.data);
      qc.invalidateQueries({ queryKey: KEY });
    },
  });
}

export function useSetTenantFeatureOverride(publicId: string) {
  return useTenantFeatureMutation(
    publicId,
    async ({
      key,
      ...payload
    }: SetTenantFeatureOverridePayload & { key: FeatureKey }) =>
      (
        await apiClient.put<{ data: TenantFeature[] }>(
          `/admin/tenants/${publicId}/features/${key}`,
          payload,
        )
      ).data,
  );
}

export function useRemoveTenantFeatureOverride(publicId: string) {
  return useTenantFeatureMutation(
    publicId,
    async ({ key, reason }: { key: FeatureKey; reason: string }) =>
      (
        await apiClient.delete<{ data: TenantFeature[] }>(
          `/admin/tenants/${publicId}/features/${key}`,
          { data: { reason } },
        )
      ).data,
  );
}
