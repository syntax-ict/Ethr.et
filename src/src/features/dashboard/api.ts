import { useQuery } from "@tanstack/react-query";
import { apiClient } from "@/api/client";
import type { operations } from "@/api/generated";

// Shapes come from the generated contract. The dashboard services build their
// lists with `->map()`, which Scramble publishes as `unknown[]`; those rows are
// restated from EmployeeDashboardService / ManagerDashboardService.

type EmployeeContract =
  operations["dashboard.employee"]["responses"][200]["content"]["application/json"];

/**
 * `entitled` and `used` are LeaveBalance's `decimal:1` casts and arrive as
 * strings ("16.0"); `remaining` is `remainingDays()`, a float. `type` is
 * `leaveType?->name`, null once the leave type has been soft-deleted.
 */
export interface DashboardLeaveBalance {
  type: string | null;
  entitled: string;
  used: string;
  remaining: number;
}

export interface DashboardHoliday {
  name: string;
  /** Amharic name; null for holidays created before bilingual names existed. */
  name_am: string | null;
  date: string;
}

/** `latest_payslip.period` is `payrollRun?->period_label`, so nullable. */
export type EmployeeDashboard = Omit<
  EmployeeContract,
  "leave_balances" | "upcoming_holidays" | "latest_payslip"
> & {
  leave_balances: DashboardLeaveBalance[];
  upcoming_holidays: DashboardHoliday[];
  latest_payslip:
    | (Omit<NonNullable<EmployeeContract["latest_payslip"]>, "period"> & {
        period: string | null;
      })
    | null;
};

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
    enabled,
  });
}
