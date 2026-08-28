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
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { apiClient } from "@/api/client";
import { useT } from "@/lib/i18n/useT";
import { toast } from "sonner";

interface AccountEntry {
  key: string;
  account_code: string;
  account_name: string;
  is_custom: boolean;
}

interface PayrollRun {
  public_id: string;
  period_label: string;
  status: string;
}

export default function AccountingSettingsPage() {
  const { t } = useT();
  return (
    <RoleGate minRole="finance_admin">
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
  const queryClient = useQueryClient();
  const [accounts, setAccounts] = useState<AccountEntry[]>([]);
  const [dirty, setDirty] = useState(false);

  const { isLoading } = useQuery({
    queryKey: ["accounting", "chart-of-accounts"],
    queryFn: async () => {
      const { data } = await apiClient.get("/accounting/chart-of-accounts");
      setAccounts(data.accounts);
      return data.accounts as AccountEntry[];
    },
  });

  const save = useMutation({
    mutationFn: async () => {
      await apiClient.put("/accounting/chart-of-accounts", { accounts });
    },
    onSuccess: () => {
      queryClient.invalidateQueries({
        queryKey: ["accounting", "chart-of-accounts"],
      });
      setDirty(false);
      toast.success(t("accounting_page.chart_saved"));
    },
    onError: () => toast.error(t("accounting_page.chart_save_failed")),
  });

  function updateAccount(
    key: string,
    field: "account_code" | "account_name",
    value: string,
  ) {
    setAccounts((prev) =>
      prev.map((a) =>
        a.key === key ? { ...a, [field]: value, is_custom: true } : a,
      ),
    );
    setDirty(true);
  }

  const keyLabels: Record<string, string> = {
    salary_expense: t("accounting_page.key_salary_expense"),
    pension_expense: t("accounting_page.key_pension_expense"),
    tax_payable: t("accounting_page.key_tax_payable"),
    pension_payable_employee: t("accounting_page.key_pension_payable_employee"),
    pension_payable_employer: t("accounting_page.key_pension_payable_employer"),
    net_salary_payable: t("accounting_page.key_net_salary_payable"),
  };

  if (isLoading) return <Skeleton className="h-64 w-full" />;

  return (
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
        {accounts.map((account) => (
          <div
            key={account.key}
            className="grid grid-cols-3 gap-2 items-center"
          >
            {/* The row label is a plain <div>, so neither input had an
                accessible name — and since every row renders the same two
                columns, "account code" alone would be ambiguous across ten
                identical pairs. Each input is named with its row *and* its
                column. */}
            <div id={`acct-${account.key}`} className="text-sm font-medium">
              {keyLabels[account.key] ?? account.key}
            </div>
            <Input
              value={account.account_code}
              onChange={(e) =>
                updateAccount(account.key, "account_code", e.target.value)
              }
              className="font-mono text-sm"
              placeholder="e.g. 5100"
              aria-label={`${keyLabels[account.key] ?? account.key} — ${t(
                "accounting_page.account_code",
                "Account code",
              )}`}
            />
            <Input
              value={account.account_name}
              onChange={(e) =>
                updateAccount(account.key, "account_name", e.target.value)
              }
              className="text-sm"
              aria-label={`${keyLabels[account.key] ?? account.key} — ${t(
                "accounting_page.account_name",
                "Account name",
              )}`}
            />
          </div>
        ))}
        {dirty && (
          <div className="flex justify-end pt-2">
            <Button
              onClick={() => save.mutate()}
              disabled={save.isPending}
              size="sm"
            >
              {save.isPending ? (
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
  );
}

function JournalExportSection() {
  const { t } = useT();
  const [selectedRun, setSelectedRun] = useState<string>("");

  const { data: runsData, isLoading: runsLoading } = useQuery({
    queryKey: ["payroll", "runs"],
    queryFn: async () => {
      const { data } = await apiClient.get("/payroll/runs");
      return data;
    },
  });

  const { data: journalData, isLoading: journalLoading } = useQuery({
    queryKey: ["accounting", "journal", selectedRun],
    queryFn: async () => {
      const { data } = await apiClient.get(
        `/accounting/journal/${selectedRun}`,
      );
      return data;
    },
    enabled: !!selectedRun,
  });

  function handleExport() {
    if (!selectedRun) return;
    const url = `/api/v1/accounting/export/${selectedRun}`;
    window.open(url, "_blank");
  }

  const runs: PayrollRun[] = runsData?.data ?? [];

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
            {runsLoading ? (
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
          <Button
            onClick={handleExport}
            disabled={!selectedRun}
            variant="outline"
          >
            <Download className="mr-2 h-4 w-4" />
            {t("audit_logs_page.export_csv")}
          </Button>
        </div>

        {journalLoading && <Skeleton className="h-40 w-full" />}

        {journalData && (
          <div className="space-y-3">
            <div className="flex items-center justify-between rounded-lg bg-muted/50 p-3 text-sm">
              <span className="font-medium">
                {t("accounting_page.reference")}: {journalData.reference}
              </span>
              <span className="text-muted-foreground">{journalData.date}</span>
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
                rows={(
                  journalData.entries as Array<{
                    account_code: string;
                    account_name: string;
                    debit_cents: number;
                    credit_cents: number;
                  }>
                )?.map((entry, i) => ({
                  key: String(i),
                  cells: [
                    <span key="c" className="font-mono">
                      {entry.account_code}
                    </span>,
                    entry.account_name,
                    entry.debit_cents > 0
                      ? formatCents(entry.debit_cents)
                      : "—",
                    entry.credit_cents > 0
                      ? formatCents(entry.credit_cents)
                      : "—",
                  ],
                }))}
                footerCells={[
                  t("accounting_page.totals"),
                  "",
                  formatCents(journalData.total_debits_cents),
                  formatCents(journalData.total_credits_cents),
                ]}
              />
            </div>
          </div>
        )}
      </CardContent>
    </Card>
  );
}

function formatCents(cents: number): string {
  return (
    (cents / 100).toLocaleString("en-ET", { minimumFractionDigits: 2 }) + " ETB"
  );
}
