"use client";

import { toast } from "sonner";
import { apiErrorMessage } from "@/lib/api/error-message";
import { useT } from "@/lib/i18n/useT";
import {
  failedApprovals,
  useBatchApprovals,
  type BatchApprovalPayload,
  type BatchApprovalResponse,
  type PendingApproval,
} from "./api";

/**
 * Approve or reject pending requests and tell the reviewer what happened.
 * The queue page and the dashboard's Pending Approvals panel share this, so a
 * decision reads the same wherever it is made.
 *
 * The batch endpoint answers 200 and reports each item on its own; one that
 * was already decided by someone else, or that this reviewer may not act on,
 * comes back as `status: "error"`. The page toasted "Action completed" for
 * those too, so a refused approval read as done.
 */
export function useDecideApprovals() {
  const { t } = useT();
  const batch = useBatchApprovals();

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
    batch.mutate(payload, {
      onSuccess: reportOutcome,
      onError: (err) =>
        toast.error(
          apiErrorMessage(err, t("approvals.action_failed", "Action failed")),
        ),
      onSettled: options?.onSettled,
    });
  }

  function approve(
    targets: PendingApproval[],
    options?: { onSettled?: () => void },
  ) {
    submit(
      {
        actions: targets.map((item) => ({
          type: item.type,
          public_id: item.public_id,
          action: "approve" as const,
        })),
      },
      options,
    );
  }

  /**
   * Rejection is recorded against the request and shown to the employee whose
   * leave or profile change was refused. It used to send the hardcoded English
   * string "Rejected by manager" for every rejection in the system, regardless
   * of the actual reason or the tenant's locale, so the employee learned only
   * that they had been refused, and the approver had no way to say why. The
   * caller collects the real reason (ReasonDialog) and a batch shares it.
   */
  function reject(
    targets: PendingApproval[],
    reason: string,
    options?: { onSettled?: () => void },
  ) {
    submit(
      {
        actions: targets.map((item) => ({
          type: item.type,
          public_id: item.public_id,
          action: "reject" as const,
          reason,
        })),
      },
      options,
    );
  }

  return { approve, reject, isPending: batch.isPending };
}
