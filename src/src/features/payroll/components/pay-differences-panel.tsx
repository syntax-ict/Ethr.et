"use client";

import { useState } from "react";
import { AlertTriangle, Loader2, Scale } from "lucide-react";
import { toast } from "sonner";
import { Button } from "@/components/ui/button";
import {
  Dialog,
  DialogContent,
  DialogDescription,
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
import { EmptyState } from "@/components/shared/empty-state";
import { apiErrorMessage } from "@/lib/api/error-message";
import { usePermissions } from "@/lib/hooks/usePermissions";
import { useDateFormatters } from "@/lib/hooks/useTenantTimezone";
import { useT } from "@/lib/i18n/useT";
import {
  useClosePayDifference,
  usePayDifferences,
  type PayDifference,
  type PayDifferenceStatus,
} from "../api";
import {
  KindLabel,
  PayDifferenceFlags,
  PayDifferenceStatusBadge,
  SignedMoney,
  usePayDifferenceMonth,
} from "./pay-difference-parts";

type Filter = "live" | PayDifferenceStatus;
const FILTERS: Filter[] = [
  "live",
  "open",
  "pending",
  "failed",
  "settled",
  "dismissed",
  "superseded",
];

/**
 * The pay differences payroll staff work through: a change back-dated into a
 * month already paid left money owed or owing. Nothing here moves money; Settle
 * records that finance paid or recovered it by hand, Dismiss that it needs
 * neither. Only an open row can be settled; an open or failed row can be dismissed.
 */
export function PayDifferencesPanel() {
  const { t } = useT();
  const { hasPermission } = usePermissions();
  const { formatDate } = useDateFormatters();
  const month = usePayDifferenceMonth();
  const [filter, setFilter] = useState<Filter>("live");
  const { data, isLoading, isError, refetch } = usePayDifferences({
    status: filter === "live" ? undefined : filter,
  });
  const close = useClosePayDifference();
  const [closing, setClosing] = useState<{
    difference: PayDifference;
    action: "settle" | "dismiss";
  } | null>(null);
  const [note, setNote] = useState("");
  // The same permission as approving a run: closing one is what approval
  // acknowledges.
  const canClose = hasPermission("payroll.approve");
  const rows = data?.data ?? [];

  const filterLabels: Record<Filter, string> = {
    live: t("pay_differences.filter_live"),
    open: t("pay_differences.status_open"),
    pending: t("pay_differences.status_pending"),
    failed: t("pay_differences.status_failed"),
    settled: t("pay_differences.status_settled"),
    dismissed: t("pay_differences.status_dismissed"),
    superseded: t("pay_differences.status_superseded"),
  };

  function dismissDialog() {
    setClosing(null);
    setNote("");
  }

  function submit(e: React.FormEvent) {
    e.preventDefault();
    if (!closing) return;
    const action = closing.action;
    close.mutate(
      {
        publicId: closing.difference.public_id,
        action,
        note: note.trim(),
      },
      {
        onSuccess: () => {
          toast.success(
            action === "settle"
              ? t("pay_differences.settled_success")
              : t("pay_differences.dismissed_success"),
          );
          dismissDialog();
        },
        onError: (err: unknown) => {
          toast.error(apiErrorMessage(err, t("pay_differences.action_failed")));
        },
      },
    );
  }

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <p className="max-w-2xl text-sm text-muted-foreground">
          {t("pay_differences.intro")}
        </p>
        <div className="w-52">
          <Label htmlFor="pay-differences-filter" className="text-xs">
            {t("pay_differences.filter_label")}
          </Label>
          <Select value={filter} onValueChange={(v) => setFilter(v as Filter)}>
            <SelectTrigger id="pay-differences-filter" className="mt-1">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              {FILTERS.map((f) => (
                <SelectItem key={f} value={f}>
                  {filterLabels[f]}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>
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
            </div>
          ))}
        </div>
      ) : isError ? (
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
      ) : rows.length === 0 ? (
        <EmptyState
          icon={Scale}
          title={t("pay_differences.empty_title")}
          description={t("pay_differences.empty_desc")}
        />
      ) : (
        <div className="overflow-x-auto rounded-xl border border-border bg-card shadow-xs">
          <table className="w-full text-sm">
            <thead className="border-b border-border bg-muted/40 text-xs text-muted-foreground">
              <tr className="text-left">
                <th className="px-4 py-2.5 font-medium">
                  {t("pay_differences.col_employee")}
                </th>
                <th className="px-4 py-2.5 font-medium">
                  {t("pay_differences.col_month")}
                </th>
                <th className="px-4 py-2.5 font-medium">
                  {t("pay_differences.col_kind")}
                </th>
                <th className="px-4 py-2.5 text-right font-medium">
                  {t("pay_differences.col_gross")}
                </th>
                <th className="px-4 py-2.5 text-right font-medium">
                  {t("pay_differences.col_net")}
                </th>
                <th className="px-4 py-2.5 font-medium">
                  {t("pay_differences.col_changed_from")}
                </th>
                <th className="px-4 py-2.5 font-medium">
                  {t("pay_differences.col_status")}
                </th>
                {canClose && (
                  <th className="px-4 py-2.5 text-right font-medium">
                    <span className="sr-only">
                      {t("pay_differences.col_actions")}
                    </span>
                  </th>
                )}
              </tr>
            </thead>
            <tbody className="divide-y divide-border">
              {rows.map((d) => (
                <tr key={d.public_id} className="align-top">
                  <td className="px-4 py-3">
                    <span className="font-medium text-foreground">
                      {d.employee_name}
                    </span>
                    <PayDifferenceFlags difference={d} />
                    {d.status === "failed" && (
                      <p className="mt-1 text-xs text-destructive">
                        {t("pay_differences.failed_hint")}
                        {d.error ? ` ${d.error}` : ""}
                      </p>
                    )}
                    {d.note && (
                      <p className="mt-1 text-xs text-muted-foreground">
                        {d.note}
                      </p>
                    )}
                  </td>
                  <td className="px-4 py-3">{month(d)}</td>
                  <td className="px-4 py-3">
                    <KindLabel kind={d.kind} />
                  </td>
                  <td className="px-4 py-3 text-right tabular-nums">
                    <SignedMoney cents={d.gross_cents} />
                  </td>
                  <td className="px-4 py-3 text-right tabular-nums">
                    <SignedMoney cents={d.net_cents} />
                  </td>
                  <td className="whitespace-nowrap px-4 py-3">
                    {formatDate(d.changed_from)}
                  </td>
                  <td className="px-4 py-3">
                    <PayDifferenceStatusBadge status={d.status} />
                  </td>
                  {canClose && (
                    <td className="whitespace-nowrap px-4 py-3 text-right">
                      <span
                        title={
                          d.status === "failed"
                            ? t("pay_differences.settle_failed_reason")
                            : undefined
                        }
                      >
                        <Button
                          size="sm"
                          variant="outline"
                          disabled={d.status !== "open"}
                          onClick={() =>
                            setClosing({ difference: d, action: "settle" })
                          }
                        >
                          {t("pay_differences.settle")}
                        </Button>
                      </span>{" "}
                      <Button
                        size="sm"
                        variant="ghost"
                        disabled={d.status !== "open" && d.status !== "failed"}
                        onClick={() =>
                          setClosing({ difference: d, action: "dismiss" })
                        }
                      >
                        {t("pay_differences.dismiss")}
                      </Button>
                    </td>
                  )}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      <Dialog
        open={closing !== null}
        onOpenChange={(open) => {
          if (!open) dismissDialog();
        }}
      >
        <DialogContent>
          <form onSubmit={submit}>
            <DialogHeader>
              <DialogTitle>
                {closing?.action === "dismiss"
                  ? t("pay_differences.dismiss_title")
                  : t("pay_differences.settle_title")}
              </DialogTitle>
              <DialogDescription>
                {closing?.action === "dismiss"
                  ? t("pay_differences.dismiss_hint")
                  : t("pay_differences.settle_hint")}
              </DialogDescription>
            </DialogHeader>
            <div className="py-4">
              <Label htmlFor="pay-difference-note">
                {t("pay_differences.note_label")}
              </Label>
              <Textarea
                id="pay-difference-note"
                className="mt-1"
                required
                maxLength={1000}
                value={note}
                onChange={(e) => setNote(e.target.value)}
              />
            </div>
            <DialogFooter>
              <Button type="button" variant="outline" onClick={dismissDialog}>
                {t("common.cancel")}
              </Button>
              <Button
                type="submit"
                disabled={close.isPending || note.trim() === ""}
              >
                {close.isPending && (
                  <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                )}
                {closing?.action === "dismiss"
                  ? t("pay_differences.dismiss_confirm")
                  : t("pay_differences.settle_confirm")}
              </Button>
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>
    </div>
  );
}
