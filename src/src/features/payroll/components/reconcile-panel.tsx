"use client";

import { useMemo, useState } from "react";
import Link from "next/link";
import { ClipboardCheck, Loader2 } from "lucide-react";
import { toast } from "sonner";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Checkbox } from "@/components/ui/checkbox";
import {
  Dialog,
  DialogContent,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { Label } from "@/components/ui/label";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { Skeleton } from "@/components/ui/skeleton";
import { Textarea } from "@/components/ui/textarea";
import { DualCalendarDateInput } from "@/components/shared/dual-calendar-date-input";
import { EmptyState } from "@/components/shared/empty-state";
import { ErrorState } from "@/components/shared/error-state";
import { SimpleTable } from "@/components/shared/simple-table";
import { useRequestableLeaveTypes, type LeaveType } from "@/features/leave/api";
import { useDateFormatters } from "@/lib/hooks/useTenantTimezone";
import { useT } from "@/lib/i18n/useT";
import { apiErrorMessage } from "@/lib/api/error-message";
import {
  exceptionHref,
  useReconciliation,
  useSettleExceptions,
  type ReconciliationException,
  type ReconciliationPeriod,
  type SettleRequest,
} from "../reconciliation";

/** An exception with the employee it belongs to, one row of the list. */
type Row = ReconciliationException & {
  key: string;
  employeePublicId: string;
  employeeName: string;
};

type StateFilter = "blocking" | "all";

/** Settled from this list: an absence by leave, lateness by a decision. */
function settleable(row: Row, latenessOn: boolean): boolean {
  if (row.state !== "open") return false;
  if (row.type === "unexplained_absence") return true;
  return (
    latenessOn &&
    (row.type === "late_arrival" || row.type === "early_leave") &&
    !!row.record_public_id
  );
}

/** Leave types an absence can be settled as: unpaid, excused, or a trip. */
function settlingTypes(types: LeaveType[]): LeaveType[] {
  return types.filter(
    (lt) =>
      lt.kind === "duty" ||
      lt.kind === "excused" ||
      (lt.kind === "leave" && !lt.is_paid),
  );
}

function monthToDate(): ReconciliationPeriod {
  const now = new Date();
  const pad = (n: number) => String(n).padStart(2, "0");
  const y = now.getFullYear();
  const m = pad(now.getMonth() + 1);
  return { start: `${y}-${m}-01`, end: `${y}-${m}-${pad(now.getDate())}` };
}

/**
 * The pay period's attendance exceptions and who has acted on them: what
 * payroll staff used to reconcile by hand. Each open absence or lateness is
 * settled here by filing the request that already exists (leave for the
 * employee, or a lateness decision), one per row; everything else links to
 * its own screen. Filing changes no pay: each request goes through its
 * approval chain first.
 */
export function ReconcilePanel() {
  const { t } = useT();
  const { formatDate } = useDateFormatters();
  const [draft, setDraft] = useState<ReconciliationPeriod>(monthToDate);
  const [period, setPeriod] = useState<ReconciliationPeriod>(draft);
  const [stateFilter, setStateFilter] = useState<StateFilter>("blocking");
  const [selected, setSelected] = useState<Set<string>>(new Set());
  const [settling, setSettling] = useState<Row[] | null>(null);

  const { data, isLoading, isError, refetch } = useReconciliation(period);
  const latenessOn = data?.lateness_deduction_enabled ?? false;

  const rows = useMemo<Row[]>(
    () =>
      (data?.employees ?? []).flatMap((employee) =>
        employee.exceptions.map((e) => ({
          ...e,
          key: `${employee.employee_public_id}|${e.type}|${e.date}`,
          employeePublicId: employee.employee_public_id,
          employeeName: employee.employee_name,
        })),
      ),
    [data],
  );
  const shown =
    stateFilter === "all"
      ? rows
      : rows.filter((r) => r.state === "open" || r.state === "pending");
  const chosen = shown.filter((r) => selected.has(r.key));

  function toggle(key: string, on: boolean) {
    setSelected((prev) => {
      const next = new Set(prev);
      if (on) next.add(key);
      else next.delete(key);
      return next;
    });
  }

  return (
    <div className="space-y-4">
      <Card>
        <CardContent className="flex flex-wrap items-end gap-4 p-4">
          <div>
            <Label>{t("payroll_reconciliation.period_start")}</Label>
            <DualCalendarDateInput
              value={draft.start}
              onChange={(v) => setDraft((p) => ({ ...p, start: v }))}
              className="mt-1"
            />
          </div>
          <div>
            <Label>{t("payroll_reconciliation.period_end")}</Label>
            <DualCalendarDateInput
              value={draft.end}
              onChange={(v) => setDraft((p) => ({ ...p, end: v }))}
              className="mt-1"
            />
          </div>
          <Button
            variant="outline"
            onClick={() => {
              setSelected(new Set());
              setPeriod(draft);
            }}
            disabled={!draft.start || !draft.end}
          >
            {t("payroll_reconciliation.show")}
          </Button>
          <div className="ml-auto">
            <Select
              value={stateFilter}
              onValueChange={(v) => setStateFilter(v as StateFilter)}
            >
              <SelectTrigger
                className="w-48"
                aria-label={t("payroll_reconciliation.filter")}
              >
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="blocking">
                  {t("payroll_reconciliation.filter_blocking")}
                </SelectItem>
                <SelectItem value="all">
                  {t("payroll_reconciliation.filter_all")}
                </SelectItem>
              </SelectContent>
            </Select>
          </div>
        </CardContent>
      </Card>

      {isLoading ? (
        <div className="space-y-3">
          {Array.from({ length: 4 }).map((_, i) => (
            <Skeleton key={i} className="h-12 w-full" />
          ))}
        </div>
      ) : isError ? (
        <ErrorState onRetry={() => refetch()} />
      ) : (
        data && (
          <>
            <div className="flex flex-wrap items-center gap-2 text-sm">
              <Badge variant="outline">
                {t("payroll_reconciliation.count_open", undefined, {
                  count: String(data.totals.open),
                })}
              </Badge>
              <Badge variant="outline">
                {t("payroll_reconciliation.count_pending", undefined, {
                  count: String(data.totals.pending),
                })}
              </Badge>
              <Badge variant="outline">
                {t("payroll_reconciliation.count_resolved", undefined, {
                  count: String(data.totals.resolved),
                })}
              </Badge>
              <span className="text-muted-foreground">
                {t(`payroll_reconciliation.mode_${data.mode}`)}
              </span>
              {data.unmatched_punches > 0 && (
                <Link
                  href="/devices"
                  className="text-muted-foreground underline underline-offset-2"
                >
                  {t("payroll_reconciliation.unmatched", undefined, {
                    count: String(data.unmatched_punches),
                  })}
                </Link>
              )}
              {chosen.length > 0 && (
                <Button
                  size="sm"
                  className="ml-auto"
                  onClick={() => setSettling(chosen)}
                >
                  {t("payroll_reconciliation.settle_selected", undefined, {
                    count: String(chosen.length),
                  })}
                </Button>
              )}
            </div>

            {shown.length === 0 ? (
              <EmptyState
                icon={ClipboardCheck}
                title={t("payroll_reconciliation.empty_title")}
                description={t("payroll_reconciliation.empty_desc")}
              />
            ) : (
              <Card>
                <CardContent className="p-0">
                  <SimpleTable
                    mobileCards
                    caption={t("payroll_reconciliation.title")}
                    headers={[
                      t("common.employee", "Employee"),
                      t("common.date", "Date"),
                      t("payroll_reconciliation.exception"),
                      t("payroll_reconciliation.minutes"),
                      t("common.status", "Status"),
                      "",
                    ]}
                    rows={shown.map((row) => {
                      const canSettle = settleable(row, latenessOn);
                      const href = exceptionHref(row);
                      return {
                        key: row.key,
                        cells: [
                          <label
                            key="e"
                            className="flex items-center gap-2 font-medium"
                          >
                            {canSettle && (
                              <Checkbox
                                checked={selected.has(row.key)}
                                onCheckedChange={(v) => toggle(row.key, !!v)}
                                aria-label={t(
                                  "payroll_reconciliation.select_row",
                                  undefined,
                                  { name: row.employeeName },
                                )}
                              />
                            )}
                            {row.employeeName}
                          </label>,
                          <span key="d" className="text-muted-foreground">
                            {formatDate(row.date)}
                          </span>,
                          <span key="t">
                            {t(`payroll_reconciliation.type_${row.type}`)}
                          </span>,
                          <span key="m" className="tabular-nums">
                            {row.minutes ?? ""}
                          </span>,
                          <Badge key="s" variant="secondary">
                            {t(`payroll_reconciliation.state_${row.state}`)}
                          </Badge>,
                          canSettle ? (
                            <Button
                              key="a"
                              size="sm"
                              variant="outline"
                              onClick={() => setSettling([row])}
                            >
                              {t("payroll_reconciliation.settle")}
                            </Button>
                          ) : href ? (
                            <Link
                              key="a"
                              href={href}
                              className="text-sm underline underline-offset-2"
                            >
                              {t("payroll_reconciliation.open_where")}
                            </Link>
                          ) : (
                            <span key="a" />
                          ),
                        ],
                      };
                    })}
                  />
                </CardContent>
              </Card>
            )}
          </>
        )
      )}

      <SettleDialog
        rows={settling}
        onClose={(filed) => {
          setSettling(null);
          if (filed) setSelected(new Set());
        }}
      />
    </div>
  );
}

/**
 * Files one request per chosen row: leave of the chosen type for each
 * absence, and a lateness decision (the measured minutes, or 0 to waive) for
 * each late or early record.
 */
function SettleDialog({
  rows,
  onClose,
}: {
  rows: Row[] | null;
  onClose: (filed: boolean) => void;
}) {
  const { t } = useT();
  const { data: leaveTypes } = useRequestableLeaveTypes();
  const settle = useSettleExceptions();
  const [leaveTypeId, setLeaveTypeId] = useState("");
  const [lateness, setLateness] = useState<"deduct" | "waive">("deduct");
  const [reason, setReason] = useState("");

  const absences = (rows ?? []).filter((r) => r.type === "unexplained_absence");
  const late = (rows ?? []).filter((r) => r.type !== "unexplained_absence");
  const options = settlingTypes(leaveTypes ?? []);
  const ready =
    reason.trim() !== "" && (absences.length === 0 || leaveTypeId !== "");

  function submit() {
    const requests: SettleRequest[] = [
      ...absences.map((r): SettleRequest => ({
        kind: "leave",
        key: r.key,
        employeePublicId: r.employeePublicId,
        leaveTypePublicId: leaveTypeId,
        date: r.date,
        reason,
      })),
      ...late.map((r): SettleRequest => ({
        kind: "lateness",
        key: r.key,
        recordPublicId: r.record_public_id ?? "",
        minutes: lateness === "waive" ? 0 : (r.minutes ?? 0),
        reason,
      })),
    ];

    settle.mutate(requests, {
      onSuccess: ({ filed, failed }) => {
        if (filed > 0) {
          toast.success(
            t("payroll_reconciliation.filed", undefined, {
              count: String(filed),
            }),
          );
        }
        if (failed.length > 0) {
          toast.error(
            apiErrorMessage(
              failed[0].error,
              t("payroll_reconciliation.file_failed", undefined, {
                count: String(failed.length),
              }),
            ),
          );
        }
        setReason("");
        onClose(failed.length === 0);
      },
    });
  }

  return (
    <Dialog
      open={rows !== null}
      onOpenChange={(open) => !open && onClose(false)}
    >
      <DialogContent>
        <DialogHeader>
          <DialogTitle>
            {t("payroll_reconciliation.settle_title", undefined, {
              count: String(rows?.length ?? 0),
            })}
          </DialogTitle>
        </DialogHeader>
        <div className="space-y-4">
          {absences.length > 0 && (
            <div>
              <Label htmlFor="settle-leave-type">
                {t("payroll_reconciliation.absence_as", undefined, {
                  count: String(absences.length),
                })}
              </Label>
              <Select value={leaveTypeId} onValueChange={setLeaveTypeId}>
                <SelectTrigger id="settle-leave-type" className="mt-1">
                  <SelectValue
                    placeholder={t("payroll_reconciliation.choose_type")}
                  />
                </SelectTrigger>
                <SelectContent>
                  {options.map((lt) => (
                    <SelectItem key={lt.public_id} value={lt.public_id}>
                      {lt.name}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
              <p className="mt-1 text-xs text-muted-foreground">
                {t("payroll_reconciliation.absence_hint")}
              </p>
            </div>
          )}
          {late.length > 0 && (
            <div>
              <Label htmlFor="settle-lateness">
                {t("payroll_reconciliation.lateness_as", undefined, {
                  count: String(late.length),
                })}
              </Label>
              <Select
                value={lateness}
                onValueChange={(v) => setLateness(v as "deduct" | "waive")}
              >
                <SelectTrigger id="settle-lateness" className="mt-1">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="deduct">
                    {t("payroll_reconciliation.lateness_deduct")}
                  </SelectItem>
                  <SelectItem value="waive">
                    {t("payroll_reconciliation.lateness_waive")}
                  </SelectItem>
                </SelectContent>
              </Select>
              <p className="mt-1 text-xs text-muted-foreground">
                {t("payroll_reconciliation.lateness_hint")}
              </p>
            </div>
          )}
          <div>
            <Label htmlFor="settle-reason">
              {t("attendance.reason_required")}
            </Label>
            <Textarea
              id="settle-reason"
              value={reason}
              onChange={(e) => setReason(e.target.value)}
              className="mt-1"
            />
          </div>
        </div>
        <DialogFooter>
          <Button variant="outline" onClick={() => onClose(false)}>
            {t("common.cancel")}
          </Button>
          <Button onClick={submit} disabled={!ready || settle.isPending}>
            {settle.isPending && (
              <Loader2 className="mr-2 h-4 w-4 animate-spin" />
            )}
            {t("payroll_reconciliation.file")}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
