import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { apiClient } from "@/api/client";
import type { PaginatedResponse } from "@/api/types";

// ── Types ─────────────────────────────────────────────────────────────────────

export interface PayrollRun {
  public_id: string;
  period_label: string;
  period_start: string;
  period_end: string;
  status: string;
  employee_count: number;
  gross_total_cents: number;
  net_total_cents: number;
  tax_total_cents: number;
  processed_at: string | null;
  approved_at: string | null;
  approved_by?: number | null;
  voided_at: string | null;
  void_reason: string | null;
  reprocessed_from_public_id?: string | null;
  entries?: PayrollEntry[];
}

export interface CalculationLogStep {
  step: string;
  [key: string]: unknown;
}

export interface CalculationLog {
  version: string;
  calculated_at: string;
  inputs: Record<string, unknown>;
  steps: CalculationLogStep[];
  outputs: Record<string, number>;
}

export interface PayrollEntry {
  public_id: string;
  employee_public_id: string;
  employee_name?: string;
  basic_salary_cents: number;
  gross_cents: number;
  income_tax_cents: number;
  employee_pension_cents: number;
  employer_pension_cents: number;
  other_deductions_cents: number;
  net_cents: number;
  period_label?: string;
  calculation_log?: CalculationLog;
}

export interface Loan {
  public_id: string;
  employee_public_id: string;
  employee_name?: string;
  /** Nested employee object — present when API includes the relation */
  employee?: { name: string; public_id: string } | null;
  amount_cents: number;
  remaining_cents: number;
  monthly_deduction_cents: number;
  reason?: string | null;
  status: string;
  disbursed_at: string | null;
  created_at: string;
}

export type CostSharingStatus =
  "active" | "suspended" | "completed" | "cancelled";

/**
 * An Ethiopian higher-education cost-sharing obligation.
 *
 * `repaid_cents` is derived server-side rather than tracked here: a client-side
 * subtraction would go wrong for a cancelled obligation, where the balance stops
 * moving while money remains unpaid.
 */
export interface CostSharing {
  public_id: string;
  employee_public_id: string;
  /** Nested employee object — present when API includes the relation */
  employee?: { name: string; public_id: string } | null;
  total_obligation_cents: number;
  outstanding_cents: number;
  repaid_cents: number;
  deduction_rate_percent: number;
  status: CostSharingStatus;
  started_on: string;
  completed_at: string | null;
  notes?: string | null;
  created_at: string;
}

// ── Payroll Runs ──────────────────────────────────────────────────────────────

/**
 * How often to re-check a run that is still computing.
 *
 * Payroll moved off the request and onto the queue — the API now returns 202
 * with the run at `processing`, and a cron-driven worker fills it in. Without
 * polling the UI would show `processing` until the user happened to reload,
 * which is indistinguishable from a run that failed.
 *
 * Only polls while something is actually in flight; a settled list goes back to
 * the normal staleTime and costs nothing.
 */
const PROCESSING_POLL_MS = 3000;

function hasRunInFlight(runs: PayrollRun[] | undefined): boolean {
  return (runs ?? []).some((run) => run.status === "processing");
}

export function usePayrollRuns(params?: { page?: number }) {
  return useQuery<PaginatedResponse<PayrollRun>>({
    queryKey: ["payroll", "runs", params],
    queryFn: async () => {
      const { data } = await apiClient.get("/payroll/runs", { params });
      return data;
    },
    staleTime: 5 * 60 * 1000,
    refetchInterval: (query) =>
      hasRunInFlight(query.state.data?.data) ? PROCESSING_POLL_MS : false,
  });
}

export function usePayrollRun(publicId: string) {
  return useQuery<PayrollRun>({
    queryKey: ["payroll", "runs", publicId],
    queryFn: async () => {
      const { data } = await apiClient.get(`/payroll/runs/${publicId}`);
      return data;
    },
    enabled: !!publicId,
    staleTime: 5 * 60 * 1000,
    // Stops on its own once the run reaches completed, approved, voided or
    // failed — `failed` matters as much as `completed` here, because a run that
    // crashed must stop being shown as in progress.
    refetchInterval: (query) =>
      query.state.data?.status === "processing" ? PROCESSING_POLL_MS : false,
  });
}

export function useProcessPayroll() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (payload: {
      period_start: string;
      period_end: string;
      idempotency_key: string;
    }) => {
      const { data } = await apiClient.post("/payroll/process", payload, {
        headers: { "Idempotency-Key": payload.idempotency_key },
      });
      return data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["payroll"] });
    },
  });
}

export function useApprovePayroll() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (publicId: string) => {
      const { data } = await apiClient.put(`/payroll/runs/${publicId}/approve`);
      return data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["payroll"] });
    },
  });
}

export function useVoidPayroll() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async ({
      publicId,
      reason,
    }: {
      publicId: string;
      reason: string;
    }) => {
      const { data } = await apiClient.post(`/payroll/runs/${publicId}/void`, {
        reason,
      });
      return data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["payroll"] });
    },
  });
}

export function useReprocessPayroll() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async ({
      publicId,
      idempotency_key,
    }: {
      publicId: string;
      idempotency_key: string;
    }) => {
      const { data } = await apiClient.post(
        `/payroll/runs/${publicId}/reprocess`,
        { idempotency_key },
        { headers: { "Idempotency-Key": idempotency_key } },
      );
      return data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["payroll"] });
    },
  });
}

// ── Payslips ──────────────────────────────────────────────────────────────────

export function useMyPayslips(params?: { page?: number }) {
  return useQuery<PaginatedResponse<PayrollEntry>>({
    queryKey: ["payroll", "payslips", "my", params],
    queryFn: async () => {
      const { data } = await apiClient.get("/payroll/payslips/my", { params });
      return data;
    },
    staleTime: 5 * 60 * 1000,
  });
}

export function useEmployeePayslips(
  employeePublicId: string,
  params?: { page?: number },
) {
  return useQuery<PaginatedResponse<PayrollEntry>>({
    queryKey: ["payroll", "payslips", employeePublicId, params],
    queryFn: async () => {
      const { data } = await apiClient.get(
        `/payroll/payslips/${employeePublicId}`,
        { params },
      );
      return data;
    },
    enabled: !!employeePublicId,
    staleTime: 5 * 60 * 1000,
  });
}

// ── Loans ─────────────────────────────────────────────────────────────────────

export function useLoans(params?: { page?: number }) {
  return useQuery<PaginatedResponse<Loan>>({
    queryKey: ["payroll", "loans", params],
    queryFn: async () => {
      const { data } = await apiClient.get("/payroll/loans", { params });
      return data;
    },
    staleTime: 5 * 60 * 1000,
  });
}

export function useLoan(publicId: string) {
  return useQuery<Loan>({
    queryKey: ["payroll", "loans", publicId],
    queryFn: async () => {
      const { data } = await apiClient.get(`/payroll/loans/${publicId}`);
      return data;
    },
    enabled: !!publicId,
    staleTime: 5 * 60 * 1000,
  });
}

export function useUpdateLoan() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async ({
      publicId,
      ...payload
    }: {
      publicId: string;
      monthly_deduction_cents?: number;
      reason?: string | null;
    }) => {
      const { data } = await apiClient.put(
        `/payroll/loans/${publicId}`,
        payload,
      );
      return data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["payroll", "loans"] });
    },
  });
}

export function useCancelLoan() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async ({
      publicId,
      reason,
    }: {
      publicId: string;
      reason: string;
    }) => {
      const { data } = await apiClient.put(
        `/payroll/loans/${publicId}/cancel`,
        { reason },
      );
      return data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["payroll", "loans"] });
    },
  });
}

export function useCreateLoan() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (payload: {
      employee_public_id: string;
      amount_cents: number;
      monthly_deduction_cents: number;
      disbursed_at?: string;
    }) => {
      const { data } = await apiClient.post("/payroll/loans", payload);
      return data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["payroll", "loans"] });
    },
  });
}

// ── Cost sharing ──────────────────────────────────────────────────────────────

export function useCostSharingList(params?: {
  page?: number;
  status?: CostSharingStatus;
}) {
  return useQuery<PaginatedResponse<CostSharing>>({
    queryKey: ["payroll", "cost-sharing", params],
    queryFn: async () => {
      const { page, status } = params ?? {};
      const { data } = await apiClient.get("/payroll/cost-sharing", {
        // The API filters via `filter[status]`, not a bare `status` param —
        // sending the wrong shape returns the unfiltered list, which looks like
        // the filter silently doing nothing.
        params: { page, ...(status ? { "filter[status]": status } : {}) },
      });
      return data;
    },
    staleTime: 5 * 60 * 1000,
  });
}

export function useCreateCostSharing() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (payload: {
      employee_public_id: string;
      total_obligation_cents: number;
      deduction_rate_percent: number;
      started_on: string;
      notes?: string | null;
    }) => {
      const { data } = await apiClient.post("/payroll/cost-sharing", payload);
      return data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["payroll", "cost-sharing"] });
    },
  });
}

/**
 * Note the absent balance fields: the outstanding amount is owned by payroll and
 * the API ignores any attempt to set it. Correcting a wrong balance is
 * cancel-and-recreate, which keeps both rows in the audit trail.
 */
export function useUpdateCostSharing() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async ({
      publicId,
      ...payload
    }: {
      publicId: string;
      deduction_rate_percent?: number;
      status?: CostSharingStatus;
      notes?: string | null;
    }) => {
      const { data } = await apiClient.put(
        `/payroll/cost-sharing/${publicId}`,
        payload,
      );
      return data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["payroll", "cost-sharing"] });
    },
  });
}

// ── Payroll Configuration ─────────────────────────────────────────────────────

export type AllowanceRuleType = "fixed" | "percentage";

export interface AllowanceRule {
  public_id: string;
  name: string;
  type: AllowanceRuleType;
  category: string;
  /** `{ amount_cents }` for a fixed rule, `{ percent }` for a percentage one. */
  formula: { amount_cents?: number; percent?: number };
  is_taxable: boolean;
  is_active: boolean;
  sort_order: number;
}

export interface AllowanceRulePayload {
  name: string;
  type: AllowanceRuleType;
  formula: { amount_cents?: number; percent?: number };
  is_taxable: boolean;
  is_active: boolean;
  sort_order: number;
}

export interface TaxBracket {
  public_id?: string;
  min_amount_cents: number;
  /** null on the final, open-ended band. */
  max_amount_cents: number | null;
  rate: number;
  deduction_cents: number;
  effective_from?: string;
  effective_to?: string | null;
}

export interface OvertimeRates {
  normal: number;
  night: number;
  holiday: number;
  holiday_night: number;
}

export interface OvertimeRatesResponse {
  rates: OvertimeRates;
  defaults: OvertimeRates;
  is_customized: boolean;
}

export function useAllowanceRules() {
  return useQuery<PaginatedResponse<AllowanceRule>>({
    queryKey: ["payroll", "rules"],
    queryFn: async () => {
      const { data } = await apiClient.get("/payroll/rules");
      return data;
    },
  });
}

export function useCreateAllowanceRule() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (payload: AllowanceRulePayload) => {
      const { data } = await apiClient.post("/payroll/rules", payload);
      return data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["payroll", "rules"] });
    },
  });
}

export function useUpdateAllowanceRule() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async ({
      publicId,
      ...payload
    }: AllowanceRulePayload & { publicId: string }) => {
      const { data } = await apiClient.put(
        `/payroll/rules/${publicId}`,
        payload,
      );
      return data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["payroll", "rules"] });
    },
  });
}

export function useDeleteAllowanceRule() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (publicId: string) => {
      await apiClient.delete(`/payroll/rules/${publicId}`);
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["payroll", "rules"] });
    },
  });
}

export function useTaxBrackets() {
  return useQuery<{ data: TaxBracket[] }>({
    queryKey: ["payroll", "tax-brackets"],
    queryFn: async () => {
      const { data } = await apiClient.get("/payroll/tax-brackets");
      return data;
    },
  });
}

export function useReplaceTaxBrackets() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (payload: {
      effective_from: string;
      brackets: TaxBracket[];
    }) => {
      const { data } = await apiClient.put("/payroll/tax-brackets", payload);
      return data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["payroll", "tax-brackets"] });
    },
  });
}

export function useOvertimeRates() {
  return useQuery<OvertimeRatesResponse>({
    queryKey: ["payroll", "overtime-rates"],
    queryFn: async () => {
      const { data } = await apiClient.get("/payroll/overtime-rates");
      return data;
    },
  });
}

export function useUpdateOvertimeRates() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (payload: OvertimeRates) => {
      const { data } = await apiClient.put("/payroll/overtime-rates", payload);
      return data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({
        queryKey: ["payroll", "overtime-rates"],
      });
    },
  });
}

// ── Bank Export ───────────────────────────────────────────────────────────────

export function downloadBankExport(publicId: string) {
  const url = `/api/v1/payroll/runs/${publicId}/export/bank`;
  window.open(url, "_blank");
}

export async function downloadBankExportCsv(publicId: string) {
  const { data } = await apiClient.get(
    `/payroll/runs/${publicId}/export/bank-csv`,
    { responseType: "blob" },
  );
  const url = URL.createObjectURL(data);
  const a = document.createElement("a");
  a.href = url;
  a.download = `bank-export-${publicId}.csv`;
  a.click();
  URL.revokeObjectURL(url);
}

// ── Payslip PDF ──────────────────────────────────────────────────────────────

export async function downloadPayslipPdf(entryPublicId: string) {
  const { data } = await apiClient.get(
    `/payroll/payslips/${entryPublicId}/pdf`,
    { responseType: "blob" },
  );
  const url = URL.createObjectURL(data);
  const a = document.createElement("a");
  a.href = url;
  a.download = `payslip-${entryPublicId}.pdf`;
  a.click();
  URL.revokeObjectURL(url);
}
