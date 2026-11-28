import { useQuery } from "@tanstack/react-query";
import type { operations } from "@/api/generated";
import { apiClient } from "@/api/client";
import { usePermissions } from "@/lib/hooks/usePermissions";

/**
 * The operations centre (docs/operations/OPERATIONS-CENTRE-SPEC.md): whether
 * background work, delivery and the deployed release are doing their job.
 * Every area carries a state, and `unknown` is not a fault.
 */
export type OperationsStatus =
  operations["adminDashboard.operations"]["responses"][200]["content"]["application/json"];

export type OperationsState = OperationsStatus["status"];

/**
 * An area carries a state and is rendered as a tile. `sweeps` is excluded
 * because it carries none: it reports what the scheduled sweeps last did, and
 * nothing there has a measured threshold to go amber on yet. Leaving it in
 * would make `areasNeedingAttention` read a `state` that does not exist.
 */
export type OperationsArea = Exclude<
  keyof OperationsStatus,
  "status" | "checked_at" | "sweeps"
>;

/** Rendering order, most likely to need action first. */
export const OPERATIONS_AREAS: OperationsArea[] = [
  "background",
  "backups",
  "delivery",
  "release",
  "failed_jobs",
  "services",
];

export function useAdminOperations() {
  const { isSuperAdmin } = usePermissions();

  return useQuery<OperationsStatus>({
    queryKey: ["admin", "operations"],
    queryFn: async () => (await apiClient.get("/admin/operations")).data,
    refetchInterval: 60_000,
    enabled: isSuperAdmin,
  });
}

/** Areas a person should look at: warnings and critical, never unknown. */
export function areasNeedingAttention(
  status: OperationsStatus,
): OperationsArea[] {
  return OPERATIONS_AREAS.filter(
    (area) =>
      status[area].state === "warning" || status[area].state === "critical",
  );
}
