import { useQuery } from "@tanstack/react-query";
import { apiClient } from "@/api/client";
import type { operations } from "@/api/generated";

// Shapes come from the generated contract.

/**
 * A leave balance's `type` is null once its leave type has been soft-deleted;
 * a holiday's `name_am` is null for holidays created before bilingual names.
 */
export type EmployeeDashboard =
  operations["dashboard.employee"]["responses"][200]["content"]["application/json"];

export function useEmployeeDashboard() {
  return useQuery<EmployeeDashboard>({
    queryKey: ["dashboard", "employee"],
    queryFn: async () => {
      const { data } = await apiClient.get("/dashboard/employee");
      return data;
    },
    staleTime: 5 * 60 * 1000,
  });
}

export type ManagerDashboard =
  operations["dashboard.manager"]["responses"][200]["content"]["application/json"];

/**
 * @param enabled Gate the request on the caller's role. Consumers render null
 * for non-supervisors, but hooks run before those early returns, so without
 * this every employee fired a request the API answers with 403.
 */
export function useManagerDashboard(enabled = true) {
  return useQuery<ManagerDashboard>({
    queryKey: ["dashboard", "manager"],
    queryFn: async () => {
      const { data } = await apiClient.get("/dashboard/manager");
      return data;
    },
    staleTime: 5 * 60 * 1000,
    // The Approvals badge and team attendance are read from here, and both
    // move while the page is open: refreshed every minute while the tab is
    // visible, and on every decision (["dashboard"] is invalidated by each
    // approval mutation). Neither happened, so the badge kept counting
    // requests already decided until a reload (audit N80).
    refetchInterval: 60 * 1000,
    enabled,
  });
}
