import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { apiClient } from "@/api/client";
import type { operations } from "@/api/generated";

// ── Types ─────────────────────────────────────────────────────────────────────
// From the generated contract (AttendanceReconciliation on the server).

export type Reconciliation =
  operations["payroll.reconciliation"]["responses"][200]["content"]["application/json"];
export type ReconciliationEmployee = Reconciliation["employees"][number];
export type ReconciliationException =
  ReconciliationEmployee["exceptions"][number];
export type MyAttendanceExceptions =
  operations["attendance.myAttendanceExceptions"]["responses"][200]["content"]["application/json"];

export type ReconciliationPeriod = { start: string; end: string };

/** Under ["payroll"] so a run's register refreshes with it. */
const keys = {
  all: ["payroll", "reconciliation"] as const,
  period: (p: ReconciliationPeriod) =>
    ["payroll", "reconciliation", p.start, p.end] as const,
  mine: ["attendance", "my-exceptions"] as const,
};

/** Every exception in a pay period, for the employees the caller reaches. */
export function useReconciliation(period: ReconciliationPeriod | null) {
  return useQuery<Reconciliation>({
    queryKey: period ? keys.period(period) : keys.all,
    queryFn: async () =>
      (
        await apiClient.get<Reconciliation>("/payroll/reconciliation", {
          params: { period_start: period?.start, period_end: period?.end },
        })
      ).data,
    enabled: !!period?.start && !!period?.end,
  });
}

/** The caller's own exceptions this month: what payroll will settle. */
export function useMyAttendanceExceptions(enabled = true) {
  return useQuery<MyAttendanceExceptions>({
    queryKey: keys.mine,
    queryFn: async () =>
      (await apiClient.get<MyAttendanceExceptions>("/attendance/my-exceptions"))
        .data,
    enabled,
    staleTime: 60 * 1000,
  });
}

/**
 * Settle exceptions by filing the requests that already exist, one per row:
 * leave for an employee (unpaid, excused or a business trip) or a lateness
 * decision. Each goes through its approval chain; nothing here changes pay.
 * Rows are filed one after another so every failure is reported for its row.
 */
export function useSettleExceptions() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (
      requests: SettleRequest[],
    ): Promise<{
      filed: number;
      failed: { key: string; error: unknown }[];
    }> => {
      let filed = 0;
      const failed: { key: string; error: unknown }[] = [];
      for (const request of requests) {
        try {
          if (request.kind === "leave") {
            await apiClient.post("/leave/request", {
              leave_type_public_id: request.leaveTypePublicId,
              employee_public_id: request.employeePublicId,
              start_date: request.date,
              end_date: request.date,
              reason: request.reason,
            });
          } else {
            await apiClient.post("/attendance/corrections", {
              kind: "lateness",
              attendance_record_public_id: request.recordPublicId,
              deduct_minutes: request.minutes,
              reason: request.reason,
            });
          }
          filed++;
        } catch (error) {
          failed.push({ key: request.key, error });
        }
      }
      return { filed, failed };
    },
    onSettled: () => {
      queryClient.invalidateQueries({ queryKey: keys.all });
      queryClient.invalidateQueries({ queryKey: ["leave"] });
      queryClient.invalidateQueries({ queryKey: ["attendance"] });
      // The dashboard's Approvals badge counts what was just filed.
      queryClient.invalidateQueries({ queryKey: ["approvals"] });
    },
  });
}

export type SettleRequest =
  | {
      kind: "leave";
      key: string;
      employeePublicId: string;
      leaveTypePublicId: string;
      date: string;
      reason: string;
    }
  | {
      kind: "lateness";
      key: string;
      recordPublicId: string;
      minutes: number;
      reason: string;
    };

/** Where an exception is settled when it is not settled from the list. */
export function exceptionHref(e: ReconciliationException): string | null {
  if (e.state === "pending") return "/approvals";
  switch (e.type) {
    case "missed_punch":
    case "auto_checkout":
      return "/attendance/corrections";
    case "overtime_unapproved":
      return "/attendance/overtime-requests";
    case "integrity_flag":
      return "/attendance/integrity";
    case "unresolved_conflict":
      return "/attendance/conflicts";
    default:
      return null;
  }
}
