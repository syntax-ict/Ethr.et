"use client";

import { useState } from "react";
import Link from "next/link";
import {
  usePendingApprovals,
  type PendingApproval,
} from "@/features/approvals/api";
import { useDecideApprovals } from "@/features/approvals/use-decide";
import { approvalSummary } from "@/features/approvals/summary";
import { usePermissions } from "@/lib/hooks/usePermissions";
import { useDateFormatters } from "@/lib/hooks/useTenantTimezone";
import { useT } from "@/lib/i18n/useT";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { Badge } from "@/components/ui/badge";
import { Skeleton } from "@/components/ui/skeleton";
import { EmployeeAvatar } from "@/components/shared/employee-avatar";
import { ReasonDialog } from "@/components/shared/reason-dialog";
import { StatusBadge } from "@/components/shared/status-badge";
import { ArrowRight, Check, CheckCircle, Loader2, X } from "lucide-react";
import { WidgetError } from "./widget-error";

/** How many requests the panel shows; the rest are a link away. */
const SHOWN = 3;

/**
 * The newest requests waiting on this reviewer, decided where they are seen.
 *
 * The panel used to be two counters ("Leave Requests 3", "Other Requests 1")
 * that each opened the queue page, so the most common thing an approver does
 * on the dashboard took a page load and a scroll (dashboard review,
 * 2026-11-03, PR 6). It now lists the first few requests with Approve and
 * Reject on each, through the same hook and the same rejection dialog as the
 * queue page, so a decision made here is recorded exactly as one made there.
 */
export function PendingApprovalsPanel() {
  const { t, locale } = useT();
  const { formatDate } = useDateFormatters();
  const { isSupervisor } = usePermissions();
  // Kept current while the tab is open, like the Approvals badge: a request
  // decided by someone else, or newly submitted, shows within a minute.
  const { data, isLoading, isError, refetch } = usePendingApprovals(
    isSupervisor,
    60 * 1000,
  );
  const decide = useDecideApprovals();
  const [rejecting, setRejecting] = useState<PendingApproval | null>(null);

  if (!isSupervisor) return null;

  const title = t("dashboard.pending_approvals", "Pending Approvals");

  if (isError) {
    return <WidgetError title={title} onRetry={() => refetch()} />;
  }

  if (isLoading || !data) {
    return (
      <Card>
        <CardHeader className="flex flex-row items-center justify-between pb-3">
          <Skeleton className="h-5 w-40" />
          <Skeleton className="h-8 w-20" />
        </CardHeader>
        <CardContent className="space-y-3">
          {Array.from({ length: 3 }).map((_, i) => (
            <Skeleton key={i} className="h-16 rounded-lg" />
          ))}
        </CardContent>
      </Card>
    );
  }

  const total = data.total;
  const items = data.items.slice(0, SHOWN);
  const more = total - items.length;

  return (
    <Card className="transition-shadow duration-300 hover:shadow-md">
      <CardHeader className="flex flex-row items-center justify-between pb-3">
        <div className="flex items-center gap-2">
          <CardTitle className="text-sm font-semibold">{title}</CardTitle>
          {total > 0 && (
            <Badge
              variant="destructive"
              className="h-5 min-w-5 justify-center rounded-full px-1.5 text-[10px] font-bold tabular-nums"
            >
              {total}
            </Badge>
          )}
        </div>
        <Button variant="ghost" size="sm" className="h-8 text-xs" asChild>
          <Link href="/approvals">
            {t("common.view_all", "View all")}
            <ArrowRight className="ml-1 h-3 w-3" aria-hidden="true" />
          </Link>
        </Button>
      </CardHeader>
      <CardContent>
        {items.length === 0 ? (
          <div className="flex flex-col items-center py-8 text-center">
            <div className="flex h-12 w-12 items-center justify-center rounded-2xl bg-status-success/10">
              <CheckCircle className="h-6 w-6 text-status-success" />
            </div>
            <p className="mt-3 text-sm font-medium text-foreground">
              {t("dashboard.all_caught_up", "No pending approvals")}
            </p>
            <p className="mt-0.5 text-xs text-muted-foreground">
              {t(
                "dashboard.no_pending_approvals",
                "New requests appear here as they arrive.",
              )}
            </p>
          </div>
        ) : (
          <ul className="space-y-2">
            {items.map((item) => (
              <li
                key={item.public_id}
                className="rounded-xl border border-border/60 p-3.5"
              >
                {/* Controls wrap under the text on a phone and are 44 px
                    there, as on the Next steps card (PR 5). */}
                <div className="flex flex-wrap items-center gap-3 sm:flex-nowrap">
                  <EmployeeAvatar
                    name={item.employee_name ?? "—"}
                    className="h-9 w-9 shrink-0"
                    fallbackClassName="bg-primary-soft text-xs font-semibold text-primary-on-soft"
                  />
                  <div className="min-w-0 flex-1">
                    <div className="flex flex-wrap items-center gap-2">
                      <p className="text-sm font-medium text-foreground">
                        {item.employee_name}
                      </p>
                      <StatusBadge status={item.type} />
                    </div>
                    <p className="text-xs text-muted-foreground">
                      {approvalSummary(item, t, formatDate, locale)}
                      <span aria-hidden="true"> · </span>
                      {formatDate(item.submitted_at)}
                    </p>
                  </div>
                  <div className="flex basis-full flex-wrap items-center gap-2 pl-12 sm:basis-auto sm:flex-nowrap sm:pl-0">
                    <Button
                      size="sm"
                      variant="outline"
                      className="h-touch flex-1 text-success-on-soft hover:bg-success-soft sm:h-8 sm:flex-none"
                      onClick={() => decide.approve([item])}
                      disabled={decide.isPending}
                    >
                      {decide.isPending ? (
                        <Loader2
                          className="mr-1 h-3 w-3 animate-spin"
                          aria-hidden="true"
                        />
                      ) : (
                        <Check className="mr-1 h-3 w-3" aria-hidden="true" />
                      )}
                      {t("common.approve", "Approve")}
                    </Button>
                    <Button
                      size="sm"
                      variant="outline"
                      className="h-touch flex-1 text-destructive hover:bg-destructive/10 sm:h-8 sm:flex-none"
                      onClick={() => setRejecting(item)}
                      disabled={decide.isPending}
                    >
                      <X className="mr-1 h-3 w-3" aria-hidden="true" />
                      {t("common.reject", "Reject")}
                    </Button>
                  </div>
                </div>
              </li>
            ))}
          </ul>
        )}

        {more > 0 && (
          <Button
            variant="ghost"
            size="sm"
            className="mt-3 h-touch px-2 text-xs sm:h-8"
            asChild
          >
            <Link href="/approvals">
              {t("dashboard.more_approvals", "See :count more", {
                count: more,
              })}
              <ArrowRight className="ml-1 h-3 w-3" aria-hidden="true" />
            </Link>
          </Button>
        )}

        <ReasonDialog
          open={rejecting !== null}
          onOpenChange={(open) => {
            if (!open) setRejecting(null);
          }}
          title={t("approvals.reject_title", "Reject request")}
          description={
            rejecting
              ? t(
                  "approvals.reject_description",
                  "This will be recorded and shown to :name. Explain why so they know what to do next.",
                  { name: rejecting.employee_name ?? "" },
                )
              : undefined
          }
          label={t("approvals.reject_reason", "Reason for rejection")}
          hint={t("approvals.reject_reason_hint", "Visible to the employee.")}
          placeholder={t(
            "approvals.reject_reason_placeholder",
            "e.g. Team is already at minimum cover for those dates.",
          )}
          confirmLabel={t("common.reject", "Reject")}
          loading={decide.isPending}
          onConfirm={(reason) => {
            if (!rejecting) return;
            decide.reject([rejecting], reason, {
              onSettled: () => setRejecting(null),
            });
          }}
        />
      </CardContent>
    </Card>
  );
}
