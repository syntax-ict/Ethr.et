import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { apiClient } from "@/api/client";
import { fetchAllPages } from "@/api/fetch-all-pages";
import type { components, operations } from "@/api/generated";
import type { PaginatedResponse } from "@/api/types";
import { csvAmount, csvFromRows } from "@/lib/utils/csv-export";
import { saveBlob } from "@/lib/utils/csv-export";

type Schemas = components["schemas"];

// ── Types ─────────────────────────────────────────────────────────────────────
// Shapes come from the generated contract, so a renamed resource field fails
// tsc here instead of rendering blank. The hand-written types these replace had
// drifted: a payslip `period_label` and loan `employee_name`/`disbursed_at`
// that no resource has ever sent, and a loan create body without the `reason`
// StoreLoanRequest accepts.

/** `entries` carry the corrected `PayrollEntry` below. */
export type PayrollRun = Omit<Schemas["PayrollRunResource"], "entries"> & {
  entries?: PayrollEntry[];
};

export interface CalculationLogStep {
  step: string;
  [key: string]: unknown;
}

/** The `PayrollEngine` trace stored on each entry (Convention #11). */
export interface CalculationLog {
  version: string;
  calculated_at: string;
  inputs: Record<string, unknown>;
  steps: CalculationLogStep[];
  outputs: Record<string, number>;
}

/**
 * Scramble publishes the `array`-cast `calculation_log` as `unknown[]`; it is
 * the object `PayrollEngine` writes, and is sent only with `?include_log=1`.
 */
export type PayrollEntry = Omit<
  Schemas["PayrollEntryResource"],
  "calculation_log"
> & { calculation_log?: CalculationLog | null };

export type Loan = Schemas["EmployeeLoanResource"];

export type CostSharingStatus = Schemas["CostSharingStatus"];

/**
 * An Ethiopian higher-education cost-sharing obligation.
 *
 * `repaid_cents` is derived server-side rather than tracked here: a client-side
 * subtraction would go wrong for a cancelled obligation, where the balance stops
 * moving while money remains unpaid.
 */
export type CostSharing = Schemas["EmployeeCostSharingResource"];

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

/**
 * Every run, newest period first — for pickers. `GET /payroll/runs` pages at
 * 25, so a picker fed the first page loses every run older than two years.
 */
export function useAllPayrollRuns() {
  return useQuery<PayrollRun[]>({
    queryKey: ["payroll", "runs", "all"],
    queryFn: () => fetchAllPages<PayrollRun>("/payroll/runs"),
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
    mutationFn: async (payload: Schemas["ProcessPayrollRequest"]) => {
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

// ── Loans ─────────────────────────────────────────────────────────────────────

/**
 * Every loan, all pages. The loans page has no pager, and `GET /payroll/loans`
 * pages at 25, so the 26th loan and every later one could not be seen.
 */
export function useLoans() {
  return useQuery<Loan[]>({
    queryKey: ["payroll", "loans"],
    queryFn: () => fetchAllPages<Loan>("/payroll/loans"),
    staleTime: 5 * 60 * 1000,
  });
}

export function useCreateLoan() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (payload: Schemas["StoreLoanRequest"]) => {
      const { data } = await apiClient.post("/payroll/loans", payload);
      return data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["payroll", "loans"] });
    },
  });
}

// ── Cost sharing ──────────────────────────────────────────────────────────────

/**
 * Every cost-sharing obligation, all pages: the page has no pager and the API
 * pages at 25.
 */
export function useCostSharingList(params?: { status?: CostSharingStatus }) {
  return useQuery<CostSharing[]>({
    queryKey: ["payroll", "cost-sharing", params],
    queryFn: () =>
      // The API filters via `filter[status]`, not a bare `status` param —
      // sending the wrong shape returns the unfiltered list, which looks like
      // the filter silently doing nothing.
      fetchAllPages<CostSharing>(
        "/payroll/cost-sharing",
        params?.status ? { "filter[status]": params.status } : {},
      ),
    staleTime: 5 * 60 * 1000,
  });
}

export function useCreateCostSharing() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (payload: Schemas["StoreCostSharingRequest"]) => {
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
    }: Schemas["UpdateCostSharingRequest"] & { publicId: string }) => {
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

export type AllowanceRulePayload = Schemas["StorePayrollRuleRequest"];
export type AllowanceRuleType = AllowanceRulePayload["type"];

/**
 * `type` and `formula` are free columns to Scramble (`string`, an `array` cast).
 * `/payroll/rules` lists allowance rules only, and StorePayrollRuleRequest
 * admits nothing but these two types and their one-key formulas:
 * `{ amount_cents }` for a fixed rule, `{ percent }` for a percentage one.
 */
export type AllowanceRule = Omit<
  Schemas["PayrollRuleResource"],
  "type" | "formula"
> & {
  type: AllowanceRuleType;
  formula: AllowanceRulePayload["formula"];
};

/** `max_amount_cents` is null on the final, open-ended band. */
export type TaxBracket = Schemas["TaxBracketResource"];

/** `rates` carries the same five multipliers as `defaults`. */
export type OvertimeRatesResponse =
  operations["overtimeRate.show"]["responses"][200]["content"]["application/json"];

export type OvertimeRates = OvertimeRatesResponse["defaults"];

/**
 * Every allowance rule, all pages. Rules past the 25th still applied in payroll
 * but could not be seen or edited, because the card read only the first page.
 */
export function useAllowanceRules() {
  return useQuery<AllowanceRule[]>({
    queryKey: ["payroll", "rules"],
    queryFn: () => fetchAllPages<AllowanceRule>("/payroll/rules"),
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
    mutationFn: async (payload: Schemas["ReplaceTaxBracketsRequest"]) => {
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
    mutationFn: async (payload: Schemas["UpdateOvertimeRatesRequest"]) => {
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

// ── Bank transfer file ────────────────────────────────────────────────────────

export type BankTransferExport =
  operations["payroll.bankExport"]["responses"][200]["content"]["application/json"];

/**
 * The run's net pay per employee with their primary bank account, from
 * `GET /payroll/runs/{run}/export/bank` (audit-logged as `payroll.bank_export`).
 *
 * The file is built from this JSON rather than downloaded from `/export/bank-csv`:
 * that CSV has no branch column, and its `csvEscape` quotes delimiters but does
 * not neutralise formulas, so an employee named `=HYPERLINK(...)` is evaluated
 * by the spreadsheet a finance officer opens it in. `csvFromRows` does both.
 */
export async function fetchBankTransferExport(
  publicId: string,
): Promise<BankTransferExport> {
  return (await apiClient.get(`/payroll/runs/${publicId}/export/bank`)).data;
}

export function bankTransferCsv(file: BankTransferExport): string {
  return csvFromRows(
    [
      "Employee Name",
      "Employee Code",
      "Bank",
      "Branch",
      "Account Number",
      "Net Amount (ETB)",
    ],
    file.rows.map((r) => [
      r.employee_name,
      r.employee_code,
      r.bank_name,
      r.branch_name,
      r.account_number,
      csvAmount(r.net_amount_cents),
    ]),
  );
}

/** The payslip PDF the server renders, saved under the entry's id. */
export async function downloadPayslip(entryPublicId: string): Promise<void> {
  const { data } = await apiClient.get<Blob>(
    `/payroll/payslips/${entryPublicId}/pdf`,
    { responseType: "blob" },
  );
  saveBlob(`payslip-${entryPublicId}.pdf`, data);
}
