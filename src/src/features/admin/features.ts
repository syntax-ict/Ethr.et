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
export type FeatureKey = components["schemas"]["PlanFeature"];
export type FeatureState = "enabled" | "hidden" | "restricted" | "disabled";
export type FeatureOverrideMode = "grant" | "revoke";
export type FeatureReason =
  | "enabled"
  | "granted"
  | "platform_disabled"
  | "revoked"
  | "not_in_rollout"
  | "plan";

/** English fallbacks for `admin_plans.feature.<key>`, shared with the plans page. */
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
};

export interface PlatformFeature {
  key: FeatureKey;
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
