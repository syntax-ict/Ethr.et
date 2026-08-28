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
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { apiClient } from "@/api/client";
import { useT } from "@/lib/i18n/useT";
import { toast } from "sonner";

interface PendingItem {
  type: string;
  public_id: string;
  employee_name: string;
  summary: string;
  submitted_at: string;
}

export default function ApprovalsPage() {
  const { t } = useT();
  const queryClient = useQueryClient();

  /** The item awaiting a rejection reason, and the reason being typed. */
  const [rejecting, setRejecting] = useState<PendingItem | null>(null);
  const [rejectReason, setRejectReason] = useState("");

  const { data, isLoading } = useQuery<{ items: PendingItem[]; total: number }>(
    {
      queryKey: ["approvals", "pending"],
      queryFn: async () => {
        const { data } = await apiClient.get("/approvals/pending");
        return data;
      },
    },
  );

  const batchAction = useMutation({
    mutationFn: async (payload: {
      actions: Array<{
        type: string;
        public_id: string;
        action: string;
        reason?: string;
      }>;
    }) => {
      const { data } = await apiClient.post("/approvals/batch", payload);
      return data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["approvals"] });
      queryClient.invalidateQueries({ queryKey: ["leave"] });
      // Approving a profile change writes to the employee record, so the
      // employee list and the submitter's own profile are both now stale.
      queryClient.invalidateQueries({ queryKey: ["profile"] });
      queryClient.invalidateQueries({ queryKey: ["profile-update-requests"] });
      queryClient.invalidateQueries({ queryKey: ["employees"] });
      toast.success(t("approvals.action_completed", "Action completed"));
    },
    onError: () => toast.error(t("approvals.action_failed", "Action failed")),
  });

  function handleApprove(item: PendingItem) {
    batchAction.mutate({
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

    batchAction.mutate(
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

  const items = data?.items ?? [];

  return (
    <RoleGate minRole="supervisor">
      <div className="space-y-6">
        <PageHeader
          title={t("approvals.title", "Pending Approvals")}
          description={`${items.length} ${t("approvals.items_waiting", "item(s) waiting for your review")}`}
        />

        {isLoading ? (
          <div className="space-y-3">
            {Array.from({ length: 4 }).map((_, i) => (
              <Skeleton key={i} className="h-20 w-full" />
            ))}
          </div>
        ) : items.length === 0 ? (
          <EmptyState
            icon={CheckSquare}
            title={t("approvals.empty_title", "All caught up!")}
            description={t(
              "approvals.empty_desc",
              "No pending approvals at this time",
            )}
          />
        ) : (
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
                        {new Date(item.submitted_at).toLocaleDateString()}
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
                      { name: rejecting.employee_name },
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
