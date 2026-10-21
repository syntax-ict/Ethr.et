"use client";

import { useState } from "react";
import Link from "next/link";
import {
  Wallet,
  Plus,
  Loader2,
  FileText,
  HandCoins,
  ChevronRight,
  AlertTriangle,
} from "lucide-react";
import { Button } from "@/components/ui/button";
import { Skeleton } from "@/components/ui/skeleton";
import { DualCalendarDateInput } from "@/components/shared/dual-calendar-date-input";
import { Label } from "@/components/ui/label";
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogFooter,
} from "@/components/ui/dialog";
import { PageActions } from "@/components/shared/page-actions";
import { StatusBadge } from "@/components/shared/status-badge";
import { CurrencyDisplay } from "@/components/shared/currency-display";
import { EmptyState } from "@/components/shared/empty-state";
import { RoleGate } from "@/components/shared/role-gate";
import { usePayrollRuns, useProcessPayroll } from "@/features/payroll/api";
import { usePermissions } from "@/lib/hooks/usePermissions";
import { useT } from "@/lib/i18n/useT";
import { useCalendar } from "@/lib/calendar/calendar-context";
import { dateFromIso } from "@/lib/calendar/date-from-iso";
import { localizedPeriodLabel } from "@/lib/calendar/period-label";
import { toast } from "sonner";
import { PlanFeatureNotice } from "@/components/shared/plan-feature-notice";
import { usePlanFeatures } from "@/features/auth/api";
import { apiErrorDetail } from "@/lib/api/error-message";
import { PaginationControls } from "@/components/shared/pagination-controls";

export default function PayrollPage() {
  const { t, locale } = useT();
  const { calendar, formatDate } = useCalendar();
  const [page, setPage] = useState(1);
  const [dialogOpen, setDialogOpen] = useState(false);
  const [periodStart, setPeriodStart] = useState("");
  const [periodEnd, setPeriodEnd] = useState("");
  const { data, isLoading, isError, refetch } = usePayrollRuns({ page });
  const { can } = usePermissions();
  // Running payroll needs the plan's `payroll` feature; the button was shown
  // on every plan and the run refused with a 403 (audit N66).
  const hasPayroll = usePlanFeatures().has("payroll");
  const processPayroll = useProcessPayroll();

  function handleRunPayroll(e: React.FormEvent) {
    e.preventDefault();
    processPayroll.mutate(
      {
        period_start: periodStart,
        period_end: periodEnd,
        idempotency_key: crypto.randomUUID(),
      },
      {
        onSuccess: () => {
          toast.success(
            t("payroll.processing_started", "Payroll processing started"),
          );
          setDialogOpen(false);
          setPeriodStart("");
          setPeriodEnd("");
        },
        onError: (err: unknown) => {
          toast.error(
            apiErrorDetail(err) ||
              t("payroll.process_failed", "Failed to process payroll"),
          );
        },
      },
    );
  }

  return (
    <RoleGate allowedRoles={["finance_admin", "tenant_admin", "super_admin"]}>
      <div className="space-y-6">
        <PageActions
          actions={
            can.processPayroll &&
            hasPayroll && (
              <Button onClick={() => setDialogOpen(true)}>
                <Plus className="mr-2 h-4 w-4" />
                {t("payroll.run", "Run Payroll")}
              </Button>
            )
          }
        />

        {!hasPayroll && <PlanFeatureNotice />}

        <div className="flex flex-wrap gap-1.5 border-b border-border/60 pb-3">
          <Button asChild variant="ghost" size="sm" className="h-8">
            <Link href="/payroll/payslips">
              <FileText className="mr-2 h-3 w-3" />{" "}
              {t("payroll.my_payslips", "My Payslips")}
            </Link>
          </Button>
          <Button asChild variant="ghost" size="sm" className="h-8">
            <Link href="/payroll/loans">
              <HandCoins className="mr-2 h-3 w-3" />{" "}
              {t("payroll.loans", "Loans")}
            </Link>
          </Button>
        </div>

        {isLoading ? (
          <div className="overflow-hidden rounded-xl border border-border bg-card shadow-xs">
            {Array.from({ length: 3 }).map((_, i) => (
              <div
                key={i}
                className="flex items-center gap-4 border-b border-border px-5 py-4 last:border-0"
              >
                <div className="flex-1 space-y-2">
                  <Skeleton className="h-4 w-40" />
                  <Skeleton className="h-3 w-56" />
                </div>
                <Skeleton className="h-4 w-28" />
                <Skeleton className="h-4 w-28" />
              </div>
            ))}
          </div>
        ) : isError ? (
          // There was no error state: a failed request rendered the empty
          // state, "No payroll runs", to a company that has run payroll.
          <EmptyState
            icon={AlertTriangle}
            title={t("common.load_failed", "Couldn't load this")}
            description={t(
              "common.load_failed_hint",
              "The data may be out of date. Try again.",
            )}
            action={
              <Button variant="outline" size="sm" onClick={() => refetch()}>
                {t("common.try_again", "Try again")}
              </Button>
            }
          />
        ) : !data?.data?.length ? (
          <EmptyState
            icon={Wallet}
            title={t("payroll.empty_title", "No payroll runs")}
            description={t(
              "payroll.empty_desc",
              "Payroll runs will appear here once processed",
            )}
          />
        ) : (
          <>
            {/* One list, one row per run, the whole row a link: a card per
                run with an outline "View All" button (for one run) made
                three runs look like three unrelated panels, and the amounts
                did not line up from row to row. */}
            <div className="overflow-hidden rounded-xl border border-border bg-card shadow-xs">
              <div className="hidden items-center gap-6 border-b border-border bg-muted/40 px-5 py-2.5 text-xs font-medium text-muted-foreground sm:flex">
                <span className="flex-1">{t("payroll.period", "Period")}</span>
                <span className="w-36 text-right">
                  {t("payroll.gross", "Gross")}
                </span>
                <span className="w-36 text-right">
                  {t("payroll.net", "Net")}
                </span>
                <span className="w-4" aria-hidden="true" />
              </div>
              <ul className="divide-y divide-border">
                {data.data.map((run) => (
                  <li key={run.public_id}>
                    <Link
                      href={`/payroll/${run.public_id}`}
                      className="group flex flex-col gap-3 px-5 py-4 transition-colors hover:bg-muted/40 focus-visible:bg-muted/40 focus-visible:outline-none sm:flex-row sm:items-center sm:gap-6"
                    >
                      <div className="min-w-0 flex-1">
                        <div className="flex flex-wrap items-center gap-2.5">
                          <span className="font-semibold text-foreground">
                            {localizedPeriodLabel(
                              run.period_label,
                              run.period_start,
                              run.period_end,
                              calendar,
                              locale,
                            )}
                          </span>
                          <StatusBadge
                            status={run.status}
                            domain="payroll_run"
                          />
                        </div>
                        <p className="mt-1 text-sm text-muted-foreground">
                          {run.employee_count}{" "}
                          {t("common.employees", "employees")} &middot;{" "}
                          {run.period_start
                            ? formatDate(dateFromIso(run.period_start), locale)
                            : ""}{" "}
                          –{" "}
                          {run.period_end
                            ? formatDate(dateFromIso(run.period_end), locale)
                            : ""}
                        </p>
                      </div>
                      <div className="flex gap-6 sm:contents">
                        <div className="sm:w-36 sm:text-right">
                          <p className="text-xs text-muted-foreground sm:hidden">
                            {t("payroll.gross", "Gross")}
                          </p>
                          <CurrencyDisplay
                            cents={run.gross_total_cents}
                            className="text-sm tabular-nums text-muted-foreground"
                          />
                        </div>
                        <div className="sm:w-36 sm:text-right">
                          <p className="text-xs text-muted-foreground sm:hidden">
                            {t("payroll.net", "Net")}
                          </p>
                          <CurrencyDisplay
                            cents={run.net_total_cents}
                            className="text-sm font-semibold tabular-nums text-foreground"
                          />
                        </div>
                      </div>
                      <ChevronRight
                        className="hidden h-4 w-4 shrink-0 text-muted-foreground transition-transform group-hover:translate-x-0.5 sm:block"
                        aria-hidden="true"
                      />
                    </Link>
                  </li>
                ))}
              </ul>
            </div>

            <PaginationControls meta={data.meta} onPageChange={setPage} />
          </>
        )}
        <Dialog open={dialogOpen} onOpenChange={setDialogOpen}>
          <DialogContent>
            <DialogHeader>
              <DialogTitle>{t("payroll.run", "Run Payroll")}</DialogTitle>
            </DialogHeader>
            <form onSubmit={handleRunPayroll} className="space-y-4">
              <div>
                <Label htmlFor="period_start">
                  {t("payroll.period_start", "Period Start")}
                </Label>
                <DualCalendarDateInput
                  id="period_start"
                  value={periodStart}
                  onChange={setPeriodStart}
                  required
                  className="mt-1"
                />
              </div>
              <div>
                <Label htmlFor="period_end">
                  {t("payroll.period_end", "Period End")}
                </Label>
                <DualCalendarDateInput
                  id="period_end"
                  value={periodEnd}
                  onChange={setPeriodEnd}
                  required
                  className="mt-1"
                />
              </div>
              <DialogFooter>
                <Button
                  type="button"
                  variant="outline"
                  onClick={() => setDialogOpen(false)}
                >
                  {t("common.cancel", "Cancel")}
                </Button>
                <Button type="submit" disabled={processPayroll.isPending}>
                  {processPayroll.isPending && (
                    <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                  )}
                  {t("payroll.process", "Process Payroll")}
                </Button>
              </DialogFooter>
            </form>
          </DialogContent>
        </Dialog>
      </div>
    </RoleGate>
  );
}
