"use client";

import { useState } from "react";
import { CheckSquare, Check, X, Loader2 } from "lucide-react";
import { Button } from "@/components/ui/button";
import { ReasonDialog } from "@/components/shared/reason-dialog";
import { EmployeeAvatar } from "@/components/shared/employee-avatar";
import { Skeleton } from "@/components/ui/skeleton";
import { StatusBadge } from "@/components/shared/status-badge";
import { EmptyState } from "@/components/shared/empty-state";
import { RoleGate } from "@/components/shared/role-gate";
import { QueryBoundary } from "@/components/patterns/QueryBoundary";
import {
  failedApprovals,
  useBatchApprovals,
  usePendingApprovals,
  type BatchApprovalPayload,
  type BatchApprovalResponse,
  type PendingApproval,
} from "@/features/approvals/api";
import { useDateFormatters } from "@/lib/hooks/useTenantTimezone";
import { useT } from "@/lib/i18n/useT";
import { toast } from "sonner";
import { apiErrorMessage } from "@/lib/api/error-message";

export default function ApprovalsPage() {
  const { t } = useT();
  const { formatDate } = useDateFormatters();

  /** The item awaiting a rejection reason; ReasonDialog holds the reason. */
  const [rejecting, setRejecting] = useState<PendingApproval | null>(null);

  const pending = usePendingApprovals();
  const batchAction = useBatchApprovals();

  /**
   * The batch endpoint answers 200 and reports each item on its own; one that
   * was already decided by someone else, or that this reviewer may not act on,
   * comes back as `status: "error"`. The page toasted "Action completed" for
   * those too, so a refused approval read as done.
   */
  function reportOutcome(response: BatchApprovalResponse) {
    const failed = failedApprovals(response);
    if (failed.length === 0) {
      toast.success(t("approvals.action_completed", "Action completed"));
      return;
    }
    toast.error(t("approvals.action_failed", "Action failed"), {
      description: failed
        .map((f) => f.detail)
        .filter(Boolean)
        .join("; "),
    });
  }

  function submit(
    payload: BatchApprovalPayload,
    options?: { onSettled?: () => void },
  ) {
    batchAction.mutate(payload, {
      onSuccess: reportOutcome,
      onError: (err) =>
        toast.error(
          apiErrorMessage(err, t("approvals.action_failed", "Action failed")),
        ),
      onSettled: options?.onSettled,
    });
  }

  function handleApprove(item: PendingApproval) {
    submit({
      actions: [
        { type: item.type, public_id: item.public_id, action: "approve" },
      ],
    });
  }

  /**
   * Rejection is recorded against the request and shown to the employee whose
   * leave or profile change was refused. It used to send the hardcoded English
   * string "Rejected by manager" for every rejection in the system, regardless
   * of the actual reason or the tenant's locale — so the employee learned only
   * that they had been refused, and the approver had no way to say why.
   * Collect the real reason instead.
   */
  function confirmReject(reason: string) {
    if (!rejecting) return;

    submit(
      {
        actions: [
          {
            type: rejecting.type,
            public_id: rejecting.public_id,
            action: "reject",
            reason,
          },
        ],
      },
      {
        onSettled: () => setRejecting(null),
      },
    );
  }

  const items = pending.data?.items ?? [];

  return (
    <RoleGate anyPermission={["viewTeamLeave"]}>
      <div className="space-y-6">
        {/* QueryBoundary, so a failed load says so. It used to fall through
            to the empty state: a reviewer whose queue could not be read was
            told "No pending approvals". */}
        <QueryBoundary
          query={pending}
          loading={
            <div className="overflow-hidden rounded-xl border border-border bg-card shadow-xs">
              {Array.from({ length: 4 }).map((_, i) => (
                <div
                  key={i}
                  className="flex items-center gap-3 border-b border-border px-5 py-4 last:border-0"
                >
                  <Skeleton className="h-9 w-9 rounded-full" />
                  <div className="flex-1 space-y-2">
                    <Skeleton className="h-4 w-48" />
                    <Skeleton className="h-3 w-64" />
                  </div>
                  <Skeleton className="h-8 w-40" />
                </div>
              ))}
            </div>
          }
          isEmpty={(data) => data.items.length === 0}
          empty={
            <EmptyState
              icon={CheckSquare}
              title={t("approvals.empty_title", "No pending approvals")}
              description={t(
                "approvals.empty_desc",
                "No pending approvals at this time",
              )}
            />
          }
        >
          {() => (
            // One list with a row per request, where each was its own card:
            // a queue reads as a queue, and the actions line up.
            <ul className="divide-y divide-border overflow-hidden rounded-xl border border-border bg-card shadow-xs">
              {items.map((item) => (
                <li
                  key={item.public_id}
                  className="flex flex-col gap-3 px-5 py-4 transition-colors hover:bg-muted/30 sm:flex-row sm:items-center sm:justify-between"
                >
                  <div className="flex min-w-0 items-start gap-3">
                    <EmployeeAvatar
                      name={item.employee_name ?? "—"}
                      className="h-9 w-9 shrink-0"
                      fallbackClassName="bg-primary-soft text-xs font-semibold text-primary-on-soft"
                    />
                    <div className="min-w-0">
                      <div className="flex flex-wrap items-center gap-2">
                        <p className="text-sm font-semibold text-foreground">
                          {item.employee_name}
                        </p>
                        <StatusBadge status={item.type} />
                        {item.steps > 1 && (
                          <span className="rounded-full bg-info-soft px-2 py-0.5 text-caption font-medium text-info-on-soft">
                            {t("approvals.step_of", "Step :current of :total", {
                              current: item.step,
                              total: item.steps,
                            })}
                          </span>
                        )}
                      </div>
                      <p className="mt-0.5 text-sm text-muted-foreground">
                        {item.summary}
                      </p>
                      <p className="mt-0.5 text-xs text-muted-foreground">
                        {formatDate(item.submitted_at)}
                      </p>
                    </div>
                  </div>
                  <div className="flex shrink-0 gap-2">
                    <Button
                      size="sm"
                      variant="outline"
                      className="text-success-on-soft hover:bg-success-soft"
                      onClick={() => handleApprove(item)}
                      disabled={batchAction.isPending}
                    >
                      {batchAction.isPending ? (
                        <Loader2 className="h-3 w-3 animate-spin" />
                      ) : (
                        <Check className="mr-1 h-3 w-3" />
                      )}
                      {t("common.approve", "Approve")}
                    </Button>
                    <Button
                      size="sm"
                      variant="outline"
                      className="text-destructive hover:bg-destructive/10"
                      onClick={() => setRejecting(item)}
                      disabled={batchAction.isPending}
                    >
                      <X className="mr-1 h-3 w-3" aria-hidden="true" />
                      {t("common.reject", "Reject")}
                    </Button>
                  </div>
                </li>
              ))}
            </ul>
          )}
        </QueryBoundary>

        {/* A rejection with an empty reason is the defect this dialog exists
            to fix, so ReasonDialog keeps its confirm button off until one is
            typed. */}
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
          loading={batchAction.isPending}
          onConfirm={confirmReject}
        />
      </div>
    </RoleGate>
  );
}
