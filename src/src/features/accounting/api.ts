import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { apiClient } from "@/api/client";
import type { components, operations } from "@/api/generated";
import { csvAmount, csvFromRows } from "@/lib/utils/csv-export";

export type ChartOfAccountsEntry =
  operations["accounting.chartOfAccounts"]["responses"][200]["content"]["application/json"]["accounts"][number];
export type ChartOfAccountsUpdate =
  components["schemas"]["UpdateChartOfAccountsRequest"];
/**
 * The six keys the journal reads; the server refuses any other (audit N11).
 * `GET /accounting/chart-of-accounts` returns exactly these, but Scramble types
 * that response's `key` as a plain string.
 */
export type ChartAccountKey = ChartOfAccountsUpdate["accounts"][number]["key"];

/** `GET /accounting/journal/{run}` (`AccountingExportService::journalEntries`). */
export type PayrollJournal =
  operations["accounting.journal"]["responses"][200]["content"]["application/json"];

const keys = {
  chartOfAccounts: ["accounting", "chart-of-accounts"] as const,
  journal: (runId: string) => ["accounting", "journal", runId] as const,
};

export function useChartOfAccounts() {
  return useQuery<ChartOfAccountsEntry[]>({
    queryKey: keys.chartOfAccounts,
    queryFn: async () =>
      (await apiClient.get("/accounting/chart-of-accounts")).data.accounts,
  });
}

export function useUpdateChartOfAccounts() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (payload: ChartOfAccountsUpdate) =>
      (await apiClient.put("/accounting/chart-of-accounts", payload)).data,
    onSuccess: () => {
      // A journal is computed with the current mapping, so every cached one
      // is stale too.
      queryClient.invalidateQueries({ queryKey: ["accounting"] });
    },
  });
}

export function fetchPayrollJournal(runId: string): Promise<PayrollJournal> {
  return apiClient
    .get<PayrollJournal>(`/accounting/journal/${runId}`)
    .then((r) => r.data);
}

export function usePayrollJournal(runId: string) {
  return useQuery<PayrollJournal>({
    queryKey: keys.journal(runId),
    queryFn: () => fetchPayrollJournal(runId),
    enabled: !!runId,
  });
}

/**
 * The journal as CSV, built here rather than downloaded from
 * `GET /accounting/export/{run}`: that endpoint formats amounts with
 * `number_format($cents / 100, 2)`, whose thousands separator is a comma, so
 * every line over 1,000 ETB splits across two columns — the defect the bank
 * transfer file had. Same columns and totals row as the server's file.
 */
export function journalCsv(journal: PayrollJournal): string {
  const amount = (cents: number) => (cents > 0 ? csvAmount(cents) : "");

  return csvFromRows(
    [
      "Reference",
      "Period",
      "Date",
      "Account Code",
      "Account Name",
      "Debit (ETB)",
      "Credit (ETB)",
    ],
    [
      ...journal.entries.map((e) => [
        journal.reference,
        journal.period,
        journal.date ?? "",
        e.account_code,
        e.account_name,
        amount(e.debit_cents),
        amount(e.credit_cents),
      ]),
      [
        "",
        "TOTALS",
        "",
        "",
        "",
        csvAmount(journal.total_debits_cents),
        csvAmount(journal.total_credits_cents),
      ],
    ],
  );
}

/** `journal-<period>.csv`, the name the server's export gives the file. */
export function journalFilename(journal: PayrollJournal): string {
  return `journal-${journal.period.replace(/[/\s]/g, "-")}.csv`;
}
