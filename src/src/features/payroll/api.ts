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

// ── Payroll Runs ──────────────────────────────────────────────────────────────

export function usePayrollRuns(params?: { page?: number }) {
  return useQuery<PaginatedResponse<PayrollRun>>({
    queryKey: ["payroll", "runs", params],
    queryFn: async () => {
      const { data } = await apiClient.get("/payroll/runs", { params });
      return data;
    },
    staleTime: 5 * 60 * 1000,
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
      const { data } = await apiClient.post(
        `/payroll/runs/${publicId}/void`,
        { reason },
      );
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
