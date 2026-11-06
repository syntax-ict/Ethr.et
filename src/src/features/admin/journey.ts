import { useQuery } from "@tanstack/react-query";
import type { operations } from "@/api/generated";
import { apiClient } from "@/api/client";
import { usePermissions } from "@/lib/hooks/usePermissions";

/**
 * Where each organisation is between sign-up and using ETHR
 * (docs/operations/TENANT-JOURNEY.md). Counts, dates and reason codes only;
 * the platform sees how an organisation is doing, not who its people are.
 */
export type TenantJourney =
  operations["adminTenantJourney.show"]["responses"][200]["content"]["application/json"]["data"];

export type JourneySummary =
  operations["adminTenantJourney.summary"]["responses"][200]["content"]["application/json"]["data"];

/** In order: a later stage is further along. */
export const JOURNEY_STAGES = [
  "registered",
  "configured",
  "people_added",
  "team_activated",
  "first_use",
  "successfully_using",
] as const;

export type JourneyStage = (typeof JOURNEY_STAGES)[number];

export function isJourneyStage(value: string | null): value is JourneyStage {
  return (JOURNEY_STAGES as readonly string[]).includes(value ?? "");
}

export type JourneyHealth = "ok" | "warning" | "critical" | "unknown";

/** A health state this build does not know reads as unknown, never as fine. */
export function journeyHealth(value: string | null): JourneyHealth {
  return value === "ok" || value === "warning" || value === "critical"
    ? value
    : "unknown";
}

export function useTenantJourney(publicId: string) {
  const { isSuperAdmin } = usePermissions();

  return useQuery<TenantJourney>({
    queryKey: ["admin", "tenants", publicId, "journey"],
    queryFn: async () =>
      (await apiClient.get(`/admin/tenants/${publicId}/journey`)).data.data,
    enabled: isSuperAdmin && !!publicId,
    staleTime: 2 * 60 * 1000,
  });
}

export function useTenantJourneySummary() {
  const { isSuperAdmin } = usePermissions();

  return useQuery<JourneySummary>({
    queryKey: ["admin", "tenants", "journey-summary"],
    queryFn: async () =>
      (await apiClient.get("/admin/tenants/journey-summary")).data.data,
    enabled: isSuperAdmin,
    staleTime: 2 * 60 * 1000,
  });
}
