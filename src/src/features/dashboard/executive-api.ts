"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { apiClient } from "@/api/client";

/**
 * Shared data layer for the persona-scoped executive dashboards (CEO / HR
 * Director / Finance / Operations / Regional Manager). Every hook accepts an
 * optional `branchPublicId` — when set, the request is scoped to that branch;
 * when omitted, the backend decides the scope from the caller's permission
 * (unrestricted for `dashboard.executive`, forced to their own branch for
 * `dashboard.regional`). See `ExecutiveDashboardController` for the
 * authorization rule this mirrors.
 */

export interface DateRangeParams {
  from?: string;
  to?: string;
  branchPublicId?: string;
}

function toQueryParams({ from, to, branchPublicId }: DateRangeParams) {
  return {
    from,
    to,
    branch: branchPublicId,
  };
}

export function useExecutiveOverview(params: DateRangeParams = {}) {
  return useQuery({
    queryKey: ["dashboard", "executive", "overview", params],
    queryFn: async () => {
      const { data } = await apiClient.get("/dashboard/executive", {
        params: toQueryParams(params),
      });
      return data;
    },
  });
}

export function useExecutiveAttendance(params: DateRangeParams = {}) {
  return useQuery({
    queryKey: ["dashboard", "executive", "attendance", params],
    queryFn: async () => {
      const { data } = await apiClient.get("/dashboard/executive/attendance", {
        params: toQueryParams(params),
      });
      return data;
    },
  });
}

export function useExecutivePayroll(params: DateRangeParams = {}) {
  return useQuery({
    queryKey: ["dashboard", "executive", "payroll", params],
    queryFn: async () => {
      const { data } = await apiClient.get("/dashboard/executive/payroll", {
        params: toQueryParams(params),
      });
      return data;
    },
  });
}

export function useExecutiveWorkforce(branchPublicId?: string) {
  return useQuery({
    queryKey: ["dashboard", "executive", "workforce", branchPublicId],
    queryFn: async () => {
      const { data } = await apiClient.get("/dashboard/executive/workforce", {
        params: { branch: branchPublicId },
      });
      return data;
    },
  });
}

export interface ComplianceItem {
  employee_name: string | null;
  employee_public_id: string | null;
  department?: string | null;
  document_type?: string | null;
  expiry_date?: string | null;
  probation_end_date?: string | null;
}

export interface ComplianceSnapshot {
  expiring_documents: { count: number; items: ComplianceItem[] };
  probation_overdue: { count: number; items: ComplianceItem[] };
  unused_leave: { count: number; applicable: boolean };
}

export function useExecutiveCompliance(branchPublicId?: string) {
  return useQuery<ComplianceSnapshot>({
    queryKey: ["dashboard", "executive", "compliance", branchPublicId],
    queryFn: async () => {
      const { data } = await apiClient.get("/dashboard/executive/compliance", {
        params: { branch: branchPublicId },
      });
      return data;
    },
  });
}

export interface ForecastPoint {
  label: string;
  value: number;
}

export interface ExecutiveForecast {
  headcount: {
    history: Array<{ month: string; count: number }>;
    projected: ForecastPoint[];
  };
  payroll_gross: {
    history: Array<{ month: string; value: number }>;
    projected: ForecastPoint[];
  };
}

export function useExecutiveForecast(branchPublicId?: string) {
  return useQuery<ExecutiveForecast>({
    queryKey: ["dashboard", "executive", "forecast", branchPublicId],
    queryFn: async () => {
      const { data } = await apiClient.get("/dashboard/executive/forecast", {
        params: { branch: branchPublicId },
      });
      return data;
    },
  });
}

export interface BranchSummary {
  public_id: string;
  name: string;
  headcount: number;
  department_count: number;
}

/** Powers the branch selector — reuses the existing branch-comparison endpoint. */
export function useBranchList(enabled: boolean) {
  return useQuery<{ branches: BranchSummary[] }>({
    queryKey: ["analytics", "branches"],
    enabled,
    queryFn: async () => {
      const { data } = await apiClient.get("/analytics/branches");
      return data;
    },
  });
}

// ── Dashboard digests (Phase 6.6) ───────────────────────────────────────────
// Recurring email summaries of the overview above, delivered by
// RunDashboardDigestsJob. See DashboardDigestController for the scoping rule
// (mirrors every read endpoint on this page: dashboard.executive picks a
// branch or none, dashboard.regional is always forced to its own).

export type DigestFrequency = "daily" | "weekly" | "monthly";

export interface DashboardDigest {
  public_id: string;
  branch_name: string | null;
  frequency: DigestFrequency;
  recipients: string[];
  next_run_at: string;
  last_run_at: string | null;
}

export function useDashboardDigests() {
  return useQuery<{ digests: DashboardDigest[] }>({
    queryKey: ["dashboard", "digests"],
    queryFn: async () => {
      const { data } = await apiClient.get("/dashboard/digests");
      return data;
    },
  });
}

export function useScheduleDashboardDigest() {
  const qc = useQueryClient();
  return useMutation<
    DashboardDigest,
    unknown,
    {
      frequency: DigestFrequency;
      recipients: string[];
      branch_public_id?: string;
    }
  >({
    mutationFn: async (payload) =>
      (await apiClient.post("/dashboard/digests", payload)).data,
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ["dashboard", "digests"] });
    },
  });
}

export function useDeleteDashboardDigest() {
  const qc = useQueryClient();
  return useMutation<void, unknown, string>({
    mutationFn: async (publicId) => {
      await apiClient.delete(`/dashboard/digests/${publicId}`);
    },
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ["dashboard", "digests"] });
    },
  });
}

// ── Alert thresholds (Phase 6.7) ────────────────────────────────────────────
// Configuring (create/list/cancel) requires dashboard.executive — a tenant-
// wide policy decision, mirroring AlertThresholdController's authorization.
// `triggered` is readable by dashboard.regional too, scoped to their branch.

export type AlertMetric =
  | "turnover_rate"
  | "attendance_rate_today"
  | "expiring_documents_count"
  | "probation_overdue_count"
  | "unused_leave_count";

export type AlertOperator = "gt" | "lt";
export type AlertSeverity = "warning" | "critical";

export interface AlertThreshold {
  public_id: string;
  metric: AlertMetric;
  operator: AlertOperator;
  threshold_value: number;
  severity: AlertSeverity;
}

export interface TriggeredAlert {
  public_id: string;
  metric: AlertMetric;
  operator: AlertOperator;
  threshold_value: number;
  current_value: number;
  severity: AlertSeverity;
}

export function useAlertThresholds() {
  return useQuery<{ thresholds: AlertThreshold[] }>({
    queryKey: ["dashboard", "alert-thresholds"],
    queryFn: async () => {
      const { data } = await apiClient.get("/dashboard/alert-thresholds");
      return data;
    },
  });
}

export function useTriggeredAlerts(branchPublicId?: string) {
  return useQuery<{ alerts: TriggeredAlert[] }>({
    queryKey: ["dashboard", "alert-thresholds", "triggered", branchPublicId],
    queryFn: async () => {
      const { data } = await apiClient.get(
        "/dashboard/alert-thresholds/triggered",
        { params: { branch: branchPublicId } },
      );
      return data;
    },
    staleTime: 60 * 1000,
  });
}

export function useCreateAlertThreshold() {
  const qc = useQueryClient();
  return useMutation<
    AlertThreshold,
    unknown,
    {
      metric: AlertMetric;
      operator: AlertOperator;
      threshold_value: number;
      severity: AlertSeverity;
    }
  >({
    mutationFn: async (payload) =>
      (await apiClient.post("/dashboard/alert-thresholds", payload)).data,
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ["dashboard", "alert-thresholds"] });
    },
  });
}

export function useDeleteAlertThreshold() {
  const qc = useQueryClient();
  return useMutation<void, unknown, string>({
    mutationFn: async (publicId) => {
      await apiClient.delete(`/dashboard/alert-thresholds/${publicId}`);
    },
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ["dashboard", "alert-thresholds"] });
    },
  });
}
