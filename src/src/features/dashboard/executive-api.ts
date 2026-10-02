"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { apiClient } from "@/api/client";
import type { components, operations } from "@/api/generated";

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

type ExecutiveOk<
  Op extends "overview" | "attendance" | "payroll" | "workforce",
> =
  operations[`executiveDashboard.${Op}`]["responses"][200]["content"]["application/json"];

export type ExecutiveOverview = ExecutiveOk<"overview">;
export type ExecutiveAttendance = ExecutiveOk<"attendance">;
export type ExecutivePayroll = ExecutiveOk<"payroll">;
export type ExecutiveWorkforce = ExecutiveOk<"workforce">;

export function useExecutiveOverview(params: DateRangeParams = {}) {
  return useQuery<ExecutiveOverview>({
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
  return useQuery<ExecutiveAttendance>({
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
  return useQuery<ExecutivePayroll>({
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
  return useQuery<ExecutiveWorkforce>({
    queryKey: ["dashboard", "executive", "workforce", branchPublicId],
    queryFn: async () => {
      const { data } = await apiClient.get("/dashboard/executive/workforce", {
        params: { branch: branchPublicId },
      });
      return data;
    },
  });
}

/**
 * From the contract. The two lists carry different rows — a document has a
 * type and an expiry, a probation a department and an end date — which the
 * hand-written single `ComplianceItem` blurred into all-optional fields.
 */
export type ComplianceSnapshot =
  operations["executiveDashboard.compliance"]["responses"][200]["content"]["application/json"];
export type ComplianceItem =
  | ComplianceSnapshot["expiring_documents"]["items"][number]
  | ComplianceSnapshot["probation_overdue"]["items"][number];

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

export type ExecutiveForecast =
  operations["executiveDashboard.forecast"]["responses"][200]["content"]["application/json"];

export type ForecastPoint = ExecutiveForecast["headcount"]["projected"][number];

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

/**
 * One row of `GET /analytics/branches` (BranchAnalyticsService::compare),
 * which the contract publishes as `unknown[]`; stated from the PHP.
 */
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

type DepartmentDetailContract =
  operations["analytics.departmentDetail"]["responses"][200]["content"]["application/json"];

/**
 * `GET /analytics/departments/{id}` (DepartmentAnalyticsService::detail).
 * Scramble cannot see through the two collection pipelines, so it publishes
 * `gender_breakdown` as a string and `employees` as `unknown[]`; these mirror
 * the PHP. `gender_breakdown` is `groupBy('gender')->map->count()`: keyed by
 * the stored value, with `""` for employees whose gender was never recorded,
 * and a JSON `[]` rather than `{}` when the department is empty.
 */
export type DepartmentDetail = Omit<
  DepartmentDetailContract,
  "gender_breakdown" | "employees"
> & {
  gender_breakdown: Record<string, number>;
  employees: Array<{ public_id: string; name: string; status: string }>;
};

/** The executive dashboard's department drill-down; idle until one is picked. */
export function useDepartmentDetail(departmentPublicId: string | null) {
  return useQuery<DepartmentDetail>({
    queryKey: ["analytics", "departments", departmentPublicId],
    enabled: departmentPublicId !== null,
    queryFn: async () =>
      (
        await apiClient.get<DepartmentDetail>(
          `/analytics/departments/${departmentPublicId}`,
        )
      ).data,
  });
}

// ── Dashboard digests (Phase 6.6) ───────────────────────────────────────────
// Recurring email summaries of the overview above, delivered by
// RunDashboardDigestsJob. See DashboardDigestController for the scoping rule
// (mirrors every read endpoint on this page: dashboard.executive picks a
// branch or none, dashboard.regional is always forced to its own).

type DigestBody = components["schemas"]["ScheduleDashboardDigestRequest"];
export type DigestFrequency = DigestBody["frequency"];

/**
 * `frequency` and `recipients` are columns ScheduleDashboardDigestRequest
 * constrains to its enum and to email strings; Scramble sees `string` and
 * `unknown[]`.
 */
export type DashboardDigest = Omit<
  operations["dashboardDigest.index"]["responses"][200]["content"]["application/json"]["digests"][number],
  "frequency" | "recipients"
> & { frequency: DigestFrequency; recipients: string[] };

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
  return useMutation<DashboardDigest, unknown, DigestBody>({
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

type AlertThresholdBody = components["schemas"]["StoreAlertThresholdRequest"];
export type AlertMetric = AlertThresholdBody["metric"];
export type AlertOperator = AlertThresholdBody["operator"];
export type AlertSeverity = AlertThresholdBody["severity"];

/**
 * The stored columns, typed by the request that wrote them: Scramble sees the
 * three enums as plain strings.
 */
export type AlertThreshold = Omit<
  operations["alertThreshold.index"]["responses"][200]["content"]["application/json"]["thresholds"][number],
  "metric" | "operator" | "severity"
> &
  Pick<AlertThresholdBody, "metric" | "operator" | "severity">;

/**
 * From AlertEvaluator::evaluate: `threshold_value` is the model's `float` cast
 * (Scramble: `string`), and a metric whose current value is null is skipped
 * before a row is built, so `current_value` is never null here.
 */
export type TriggeredAlert = AlertThreshold & { current_value: number };

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
  return useMutation<AlertThreshold, unknown, AlertThresholdBody>({
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
