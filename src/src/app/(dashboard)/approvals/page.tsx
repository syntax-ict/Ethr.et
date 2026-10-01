"use client";

import { useState } from "react";
import { CheckSquare, Check, X, Loader2 } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Textarea } from "@/components/ui/textarea";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { FormField } from "@/components/patterns/FormField";
import { Card, CardContent } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { PageHeader } from "@/components/shared/page-header";
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

export default function ApprovalsPage() {
  const { t } = useT();
  const { formatDate } = useDateFormatters();

  /** The item awaiting a rejection reason, and the reason being typed. */
  const [rejecting, setRejecting] = useState<PendingApproval | null>(null);
  const [rejectReason, setRejectReason] = useState("");

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
      onError: () => toast.error(t("approvals.action_failed", "Action failed")),
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
  function confirmReject() {
    if (!rejecting) return;

    submit(
      {
        actions: [
          {
            type: rejecting.type,
            public_id: rejecting.public_id,
            action: "reject",
            reason: rejectReason.trim(),
          },
        ],
      },
      {
        onSettled: () => {
          setRejecting(null);
          setRejectReason("");
        },
      },
    );
  }

  const items = pending.data?.items ?? [];

  return (
    <RoleGate minRole="supervisor">
      <div className="space-y-6">
        <PageHeader
          title={t("approvals.title", "Pending Approvals")}
          description={`${items.length} ${t("approvals.items_waiting", "item(s) waiting for your review")}`}
        />

        {/* QueryBoundary, so a failed load says so. It used to fall through
            to the empty state: a reviewer whose queue could not be read was
            told "All caught up!". */}
        <QueryBoundary
          query={pending}
          loading={
            <div className="space-y-3">
              {Array.from({ length: 4 }).map((_, i) => (
                <Skeleton key={i} className="h-20 w-full" />
              ))}
            </div>
          }
          isEmpty={(data) => data.items.length === 0}
          empty={
            <EmptyState
              icon={CheckSquare}
              title={t("approvals.empty_title", "All caught up!")}
              description={t(
                "approvals.empty_desc",
                "No pending approvals at this time",
              )}
            />
          }
        >
          {() => (
            <div className="space-y-3">
              {items.map((item) => (
                <Card key={item.public_id}>
                  <CardContent className="flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between">
                    <div className="flex items-start gap-3">
                      <StatusBadge status={item.type} />
                      <div>
                        <p className="text-sm font-medium text-foreground">
                          {item.employee_name}
                        </p>
                        <p className="text-sm text-muted-foreground">
                          {item.summary}
                        </p>
                        <p className="mt-0.5 text-xs text-muted-foreground">
                          {formatDate(item.submitted_at)}
                        </p>
                      </div>
                    </div>
                    <div className="flex gap-2">
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
                        onClick={() => {
                          setRejectReason("");
                          setRejecting(item);
                        }}
                        disabled={batchAction.isPending}
                      >
                        <X className="mr-1 h-3 w-3" aria-hidden="true" />
                        {t("common.reject", "Reject")}
                      </Button>
                    </div>
                  </CardContent>
                </Card>
              ))}
            </div>
          )}
        </QueryBoundary>

        <Dialog
          open={rejecting !== null}
          onOpenChange={(open) => {
            if (!open) setRejecting(null);
          }}
        >
          <DialogContent>
            <DialogHeader>
              <DialogTitle>
                {t("approvals.reject_title", "Reject request")}
              </DialogTitle>
              <DialogDescription>
                {rejecting
                  ? t(
                      "approvals.reject_description",
                      "This will be recorded and shown to :name. Explain why so they know what to do next.",
                      { name: rejecting.employee_name ?? "" },
                    )
                  : ""}
              </DialogDescription>
            </DialogHeader>

            <FormField
              id="reject_reason"
              label={t("approvals.reject_reason", "Reason for rejection")}
              required
              hint={t(
                "approvals.reject_reason_hint",
                "Visible to the employee.",
              )}
            >
              <Textarea
                rows={3}
                value={rejectReason}
                onChange={(e) => setRejectReason(e.target.value)}
                placeholder={t(
                  "approvals.reject_reason_placeholder",
                  "e.g. Team is already at minimum cover for those dates.",
                )}
              />
            </FormField>

            <DialogFooter>
              <Button variant="outline" onClick={() => setRejecting(null)}>
                {t("common.cancel", "Cancel")}
              </Button>
              <Button
                variant="destructive"
                onClick={confirmReject}
                // A rejection with an empty reason is the defect this dialog
                // exists to fix, so it is not submittable.
                disabled={
                  batchAction.isPending || rejectReason.trim().length === 0
                }
              >
                {batchAction.isPending && (
                  <Loader2 className="mr-2 h-3 w-3 animate-spin" />
                )}
                {t("common.reject", "Reject")}
              </Button>
            </DialogFooter>
          </DialogContent>
        </Dialog>
      </div>
    </RoleGate>
  );
}
