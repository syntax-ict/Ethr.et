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
    date: string;
  }>;
  pending_approvals: number;
}

export function useEmployeeDashboard() {
  return useQuery<EmployeeDashboard>({
    queryKey: ["dashboard", "employee"],
    queryFn: async () => {
      const { data } = await apiClient.get("/dashboard/employee");
      return data;
    },
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

export function useManagerDashboard() {
  return useQuery<ManagerDashboard>({
    queryKey: ["dashboard", "manager"],
    queryFn: async () => {
      const { data } = await apiClient.get("/dashboard/manager");
      return data;
    },
  });
}
