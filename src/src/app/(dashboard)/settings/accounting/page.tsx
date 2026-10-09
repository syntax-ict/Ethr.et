"use client";

import { useState } from "react";
import { Save, Download, Loader2, BookOpen } from "lucide-react";
import { Button } from "@/components/ui/button";
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Skeleton } from "@/components/ui/skeleton";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { PageHeader } from "@/components/shared/page-header";
import { SimpleTable } from "@/components/shared/simple-table";
import { RoleGate } from "@/components/shared/role-gate";
import { QueryBoundary } from "@/components/patterns/QueryBoundary";
import {
  journalCsv,
  journalFilename,
  useChartOfAccounts,
  usePayrollJournal,
  useUpdateChartOfAccounts,
  type ChartAccountKey,
  type ChartOfAccountsEntry,
} from "@/features/accounting/api";
import { useAllPayrollRuns } from "@/features/payroll/api";
import { saveCsv } from "@/lib/utils/csv-export";
import { formatETB } from "@/lib/utils/currency";
import { useT } from "@/lib/i18n/useT";
import { toast } from "sonner";

type AccountEdit = Partial<
  Pick<ChartOfAccountsEntry, "account_code" | "account_name">
>;

export default function AccountingSettingsPage() {
  const { t } = useT();
  return (
    // Every accounting endpoint authorizes `payroll.viewAll`.
    <RoleGate anyPermission={["viewPayrollRuns"]}>
      <div className="space-y-8">
        <PageHeader
          title={t("accounting_page.title")}
          description={t("accounting_page.description")}
        />
        <ChartOfAccountsSection />
        <JournalExportSection />
      </div>
    </RoleGate>
  );
}

function ChartOfAccountsSection() {
  const { t } = useT();
  const accountsQuery = useChartOfAccounts();
  const updateChart = useUpdateChartOfAccounts();

  // Unsaved edits, by account key, laid over the server's rows. The rows used
  // to be copied into state from inside the query function, which runs only on
  // a fetch — so returning to this page while the cached chart was still fresh
  // rendered no rows at all.
  const [edits, setEdits] = useState<Record<string, AccountEdit>>({});
  const dirty = Object.keys(edits).length > 0;

  function updateAccount(key: string, field: keyof AccountEdit, value: string) {
    setEdits((prev) => ({ ...prev, [key]: { ...prev[key], [field]: value } }));
  }

  function save(accounts: ChartOfAccountsEntry[]) {
    // Only the edited rows: the server stores each row it is sent as a
    // tenant override, so sending all six would pin the untouched defaults.
    const changed = accounts
      .filter((a) => edits[a.key])
      .map((a) => ({
        key: a.key as ChartAccountKey,
        account_code: edits[a.key].account_code ?? a.account_code,
        account_name: edits[a.key].account_name ?? a.account_name,
      }));

    updateChart.mutate(
      { accounts: changed },
      {
        onSuccess: () => {
          setEdits({});
          toast.success(t("accounting_page.chart_saved"));
        },
        onError: () => toast.error(t("accounting_page.chart_save_failed")),
      },
    );
  }

  const keyLabels: Record<string, string> = {
    salary_expense: t("accounting_page.key_salary_expense"),
    pension_expense: t("accounting_page.key_pension_expense"),
    tax_payable: t("accounting_page.key_tax_payable"),
    pension_payable_employee: t("accounting_page.key_pension_payable_employee"),
    pension_payable_employer: t("accounting_page.key_pension_payable_employer"),
    net_salary_payable: t("accounting_page.key_net_salary_payable"),
  };

  return (
    <QueryBoundary
      query={accountsQuery}
      loading={<Skeleton className="h-64 w-full" />}
    >
      {(accounts) => (
        <Card>
          <CardHeader>
            <CardTitle className="flex items-center gap-2">
              <BookOpen className="h-5 w-5" />
              {t("accounting_page.chart_of_accounts")}
            </CardTitle>
            <CardDescription>{t("accounting_page.chart_desc")}</CardDescription>
          </CardHeader>
          <CardContent className="space-y-4">
            <div className="grid grid-cols-3 gap-2 text-xs font-medium text-muted-foreground pb-1 border-b">
              <span>{t("accounting_page.payroll_component")}</span>
              <span>{t("accounting_page.account_code")}</span>
              <span>{t("accounting_page.account_name")}</span>
            </div>
            {accounts.map((account) => {
              const label = keyLabels[account.key] ?? account.key;
              const edit = edits[account.key] ?? {};

              return (
                <div
                  key={account.key}
                  className="grid grid-cols-3 gap-2 items-center"
                >
                  {/* The row label is a plain <div>, so neither input had an
                      accessible name — and since every row renders the same
                      two columns, "account code" alone would be ambiguous
                      across ten identical pairs. Each input is named with its
                      row *and* its column. */}
                  <div className="text-sm font-medium">{label}</div>
                  <Input
                    value={edit.account_code ?? account.account_code}
                    onChange={(e) =>
                      updateAccount(account.key, "account_code", e.target.value)
                    }
                    className="font-mono text-sm"
                    placeholder="e.g. 5100"
                    maxLength={20}
                    aria-label={`${label} — ${t(
                      "accounting_page.account_code",
                      "Account code",
                    )}`}
                  />
                  <Input
                    value={edit.account_name ?? account.account_name}
                    onChange={(e) =>
                      updateAccount(account.key, "account_name", e.target.value)
                    }
                    className="text-sm"
                    maxLength={100}
                    aria-label={`${label} — ${t(
                      "accounting_page.account_name",
                      "Account name",
                    )}`}
                  />
                </div>
              );
            })}
            {dirty && (
              <div className="flex justify-end pt-2">
                <Button
                  onClick={() => save(accounts)}
                  disabled={updateChart.isPending}
                  size="sm"
                >
                  {updateChart.isPending ? (
                    <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                  ) : (
                    <Save className="mr-2 h-4 w-4" />
                  )}
                  {t("leave_types_page.save_changes")}
                </Button>
              </div>
            )}
          </CardContent>
        </Card>
      )}
    </QueryBoundary>
  );
}

function JournalExportSection() {
  const { t } = useT();
  const [selectedRun, setSelectedRun] = useState<string>("");

  const runsQuery = useAllPayrollRuns();
  const journalQuery = usePayrollJournal(selectedRun);
  const journal = journalQuery.data;

  // Built from the journal on screen rather than opened from
  // `GET /accounting/export/{run}`, whose amounts are grouped with commas and
  // so split across two columns above 1,000 ETB — see `journalCsv`.
  function handleExport() {
    if (!journal) return;
    saveCsv(journalFilename(journal), journalCsv(journal));
  }

  const runs = runsQuery.data ?? [];

  return (
    <Card>
      <CardHeader>
        <CardTitle>{t("accounting_page.journal_export")}</CardTitle>
        <CardDescription>
          {t("accounting_page.journal_export_desc")}
        </CardDescription>
      </CardHeader>
      <CardContent className="space-y-4">
        <div className="flex items-end gap-4">
          <div className="flex-1 space-y-2">
            <Label htmlFor="payroll_run">
              {t("accounting_page.payroll_run")}
            </Label>
            {runsQuery.isLoading ? (
              <Skeleton className="h-9 w-full" />
            ) : (
              <Select value={selectedRun} onValueChange={setSelectedRun}>
                <SelectTrigger id="payroll_run">
                  <SelectValue placeholder={t("accounting_page.select_run")} />
                </SelectTrigger>
                <SelectContent>
                  {runs.map((run) => (
                    <SelectItem key={run.public_id} value={run.public_id}>
                      {run.period_label} — {run.status}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            )}
          </div>
          <Button onClick={handleExport} disabled={!journal} variant="outline">
            <Download className="mr-2 h-4 w-4" />
            {t("audit_logs_page.export_csv")}
          </Button>
        </div>

        {selectedRun && (
          <QueryBoundary
            query={journalQuery}
            loading={<Skeleton className="h-40 w-full" />}
          >
            {(journalData) => (
              <div className="space-y-3">
                <div className="flex items-center justify-between rounded-lg bg-muted/50 p-3 text-sm">
                  <span className="font-medium">
                    {t("accounting_page.reference")}: {journalData.reference}
                  </span>
                  <span className="text-muted-foreground">
                    {journalData.date}
                  </span>
                  {journalData.is_balanced ? (
                    <span className="rounded-full bg-success-soft px-2 py-0.5 text-xs font-medium text-success-on-soft">
                      {t("accounting_page.balanced")}
                    </span>
                  ) : (
                    <span className="rounded-full bg-destructive-soft px-2 py-0.5 text-xs font-medium text-destructive-on-soft">
                      {t("accounting_page.unbalanced")}
                    </span>
                  )}
                </div>

                <div className="overflow-hidden rounded-lg border">
                  <SimpleTable
                    caption={t("accounting_page.title", "Journal entries")}
                    headers={[
                      t("accounting_page.account_code"),
                      t("accounting_page.account_name"),
                      t("accounting_page.debit_etb"),
                      t("accounting_page.credit_etb"),
                    ]}
                    align={["left", "left", "right", "right"]}
                    rows={journalData.entries.map((entry, i) => ({
                      key: String(i),
                      cells: [
                        <span key="c" className="font-mono">
                          {entry.account_code}
                        </span>,
                        entry.account_name,
                        entry.debit_cents > 0
                          ? formatETB(entry.debit_cents)
                          : "—",
                        entry.credit_cents > 0
                          ? formatETB(entry.credit_cents)
                          : "—",
                      ],
                    }))}
                    footerCells={[
                      t("accounting_page.totals"),
                      "",
                      formatETB(journalData.total_debits_cents),
                      formatETB(journalData.total_credits_cents),
                    ]}
                  />
                </div>
              </div>
            )}
          </QueryBoundary>
        )}
      </CardContent>
    </Card>
  );
}
