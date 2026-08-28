import { useQuery } from "@tanstack/react-query";
import { apiClient } from "@/api/client";

export interface EmployeeDashboard {
  attendance_today: {
    status: string;
    check_in?: string;
    check_out?: string;
    worked_minutes?: number;
  } | null;
  leave_balances: Array<{
    type: string;
    entitled: number;
    used: number;
    remaining: number;
  }>;
  latest_payslip: {
    period: string;
    net_cents: number;
    gross_cents: number;
  } | null;
  upcoming_holidays: Array<{
    name: string;
    /** Amharic name; null for holidays created before bilingual names existed. */
    name_am: string | null;
    date: string;
  }>;
  pending_approvals: number;
  tenant_summary: {
    employee_count: number;
    department_count: number;
    branch_count: number;
  };
  onboarding_complete: boolean;
}

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

export interface ManagerDashboard {
  team_attendance: { present: number; absent: number; late: number };
  pending_approvals: { leave: number; total: number };
  team_on_leave: Array<{
    employee_name: string;
    start_date: string;
    end_date: string;
  }>;
  team_size: number;
}

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
