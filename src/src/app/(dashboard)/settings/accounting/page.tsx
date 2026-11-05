"use client";

import { useState } from "react";
import { Save, Download, Loader2, BookOpen } from "lucide-react";
import { Badge } from "@/components/ui/badge";
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
import { statusLabelKeyOf } from "@/lib/status/registry";
import { useCalendar } from "@/lib/calendar/calendar-context";
import { localizedPeriodLabel } from "@/lib/calendar/period-label";
import { useDateFormatters } from "@/lib/hooks/useTenantTimezone";
import { toast } from "sonner";
import { apiErrorMessage } from "@/lib/api/error-message";

type AccountEdit = Partial<
  Pick<ChartOfAccountsEntry, "account_code" | "account_name">
>;

export default function AccountingSettingsPage() {
  return (
    // Every accounting endpoint authorizes `payroll.viewAll`.
    <RoleGate anyPermission={["viewPayrollRuns"]}>
      <div className="space-y-8">
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
        onError: (err) =>
          toast.error(
            apiErrorMessage(
              err,
              t(
                "accounting_page.chart_save_failed",
                "The chart of accounts could not be saved.",
              ),
            ),
          ),
      },
    );
  }

  const keyLabels: Record<string, string> = {
    salary_expense: t("accounting_page.key_salary_expense", "Salary expense"),
    pension_expense: t(
      "accounting_page.key_pension_expense",
      "Pension expense (employer)",
    ),
    tax_payable: t("accounting_page.key_tax_payable", "Income tax payable"),
    pension_payable_employee: t(
      "accounting_page.key_pension_payable_employee",
      "Pension payable (employee)",
    ),
    pension_payable_employer: t(
      "accounting_page.key_pension_payable_employer",
      "Pension payable (employer)",
    ),
    net_salary_payable: t(
      "accounting_page.key_net_salary_payable",
      "Net salary payable",
    ),
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
              <BookOpen className="h-5 w-5" aria-hidden="true" />
              {t("accounting_page.chart_of_accounts", "Chart of accounts")}
            </CardTitle>
            <CardDescription>
              {t(
                "accounting_page.chart_desc",
                "Enter the account code and name your accounting system uses for each payroll component.",
              )}
            </CardDescription>
          </CardHeader>
          <CardContent className="space-y-4">
            {/* The column headers are for wide screens; on a phone each row
                stacks and carries its own visible labels instead. */}
            <div
              className="hidden border-b pb-1 text-label text-muted-foreground sm:grid sm:grid-cols-3 sm:gap-2"
              aria-hidden="true"
            >
              <span>
                {t("accounting_page.payroll_component", "Payroll component")}
              </span>
              <span>{t("accounting_page.account_code", "Account code")}</span>
              <span>{t("accounting_page.account_name", "Account name")}</span>
            </div>
            {accounts.map((account) => {
              const label = keyLabels[account.key] ?? account.key;
              const edit = edits[account.key] ?? {};
              const codeId = `account-code-${account.key}`;
              const nameId = `account-name-${account.key}`;

              return (
                <div
                  key={account.key}
                  className="grid gap-2 border-b pb-3 last:border-b-0 sm:grid-cols-3 sm:items-center sm:border-b-0 sm:pb-0"
                >
                  <div className="text-sm font-medium">{label}</div>
                  {/* Every row renders the same two columns, so "Account code"
                      alone would be ambiguous across six identical pairs: each
                      input is named with its row and its column. The label is
                      visible on phones, where the column header is hidden. */}
                  <div className="space-y-1">
                    <Label htmlFor={codeId} className="sm:sr-only">
                      {t(
                        "accounting_page.account_code_for",
                        "Account code for :component",
                        { component: label },
                      )}
                    </Label>
                    <Input
                      id={codeId}
                      value={edit.account_code ?? account.account_code}
                      onChange={(e) =>
                        updateAccount(
                          account.key,
                          "account_code",
                          e.target.value,
                        )
                      }
                      className="font-mono text-sm"
                      placeholder="5100"
                      maxLength={20}
                    />
                  </div>
                  <div className="space-y-1">
                    <Label htmlFor={nameId} className="sm:sr-only">
                      {t(
                        "accounting_page.account_name_for",
                        "Account name for :component",
                        { component: label },
                      )}
                    </Label>
                    <Input
                      id={nameId}
                      value={edit.account_name ?? account.account_name}
                      onChange={(e) =>
                        updateAccount(
                          account.key,
                          "account_name",
                          e.target.value,
                        )
                      }
                      className="text-sm"
                      maxLength={100}
                    />
                  </div>
                </div>
              );
            })}
            {dirty && (
              <div className="flex flex-wrap justify-end gap-2 pt-2">
                <Button
                  variant="ghost"
                  size="sm"
                  onClick={() => setEdits({})}
                  disabled={updateChart.isPending}
                >
                  {t("accounting_page.discard_changes", "Discard changes")}
                </Button>
                <Button
                  onClick={() => save(accounts)}
                  disabled={updateChart.isPending}
                  size="sm"
                >
                  {updateChart.isPending ? (
                    <Loader2
                      className="mr-2 h-4 w-4 animate-spin"
                      aria-hidden="true"
                    />
                  ) : (
                    <Save className="mr-2 h-4 w-4" aria-hidden="true" />
                  )}
                  {t("common.save", "Save")}
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
  const { t, locale } = useT();
  const { calendar } = useCalendar();
  const { formatDate } = useDateFormatters();
  const [selectedRun, setSelectedRun] = useState<string>("");

  const runsQuery = useAllPayrollRuns();
  const journalQuery = usePayrollJournal(selectedRun);
  const journal = journalQuery.data;

  // Built from the journal on screen rather than opened from
  // `GET /accounting/export/{run}`, whose amounts are grouped with commas and
  // so split across two columns above 1,000 ETB — see `journalCsv`.
  function handleExport() {
    if (!journal) return;
    // Imported into accounting software, which may refuse a byte-order mark.
    saveCsv(journalFilename(journal), journalCsv(journal), {
      byteOrderMark: false,
    });
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle>
          {t("accounting_page.journal_export", "Journal entries")}
        </CardTitle>
        <CardDescription>
          {t(
            "accounting_page.journal_export_desc",
            "Choose a payroll run to see its journal entries and download them as CSV for your accounting software.",
          )}
        </CardDescription>
      </CardHeader>
      <CardContent className="space-y-4">
        <QueryBoundary
          query={runsQuery}
          loading={<Skeleton className="h-9 w-full" />}
          empty={
            <p className="py-4 text-sm text-muted-foreground">
              {t(
                "accounting_page.no_runs",
                "No payroll runs yet. Run payroll first, then export its journal.",
              )}
            </p>
          }
        >
          {(runs) => (
            <div className="flex flex-col gap-4 sm:flex-row sm:items-end">
              <div className="flex-1 space-y-2">
                <Label htmlFor="payroll_run">
                  {t("accounting_page.payroll_run", "Payroll run")}
                </Label>
                <Select value={selectedRun} onValueChange={setSelectedRun}>
                  <SelectTrigger id="payroll_run">
                    <SelectValue
                      placeholder={t(
                        "accounting_page.select_run",
                        "Choose a payroll run",
                      )}
                    />
                  </SelectTrigger>
                  <SelectContent>
                    {runs.map((run) => (
                      <SelectItem key={run.public_id} value={run.public_id}>
                        {localizedPeriodLabel(
                          run.period_label,
                          run.period_start,
                          run.period_end,
                          calendar,
                          locale,
                        )}{" "}
                        &middot; {t(statusLabelKeyOf(run.status), run.status)}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>
              <Button
                onClick={handleExport}
                disabled={!journal}
                variant="outline"
                className="w-full sm:w-auto"
              >
                <Download className="mr-2 h-4 w-4" aria-hidden="true" />
                {t("accounting_page.download_csv", "Download CSV")}
              </Button>
            </div>
          )}
        </QueryBoundary>

        {selectedRun && (
          <QueryBoundary
            query={journalQuery}
            loading={<Skeleton className="h-40 w-full" />}
          >
            {(journalData) => (
              <div className="space-y-3">
                <div className="flex flex-wrap items-center justify-between gap-2 rounded-lg bg-muted/50 p-3 text-sm">
                  <span className="font-medium">
                    {t("accounting_page.reference")}: {journalData.reference}
                  </span>
                  <span className="text-muted-foreground">
                    {journalData.date ? formatDate(journalData.date) : ""}
                  </span>
                  {journalData.is_balanced ? (
                    <Badge variant="success">
                      {t("accounting_page.balanced")}
                    </Badge>
                  ) : (
                    <Badge variant="destructive">
                      {t("accounting_page.unbalanced")}
                    </Badge>
                  )}
                </div>

                <div className="overflow-hidden rounded-lg border">
                  <SimpleTable
                    mobileCards
                    caption={t(
                      "accounting_page.journal_export",
                      "Journal entries",
                    )}
                    headers={[
                      t("accounting_page.account_code", "Account code"),
                      t("accounting_page.account_name", "Account name"),
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
