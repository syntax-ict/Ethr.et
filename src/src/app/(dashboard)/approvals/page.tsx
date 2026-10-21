"use client";

import { useState } from "react";
import { CheckSquare, Check, X, Loader2 } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Checkbox } from "@/components/ui/checkbox";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { FilterBar } from "@/components/shared/filter-bar";
import { useQueryFilters } from "@/lib/hooks/useQueryFilters";
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

/** The kinds of request the queue holds (`BatchApprovalRequest`). */
const TYPES = ["leave", "correction", "profile_update"] as const;

export default function ApprovalsPage() {
  const { t } = useT();
  const { formatDate } = useDateFormatters();

  // The queue mixed every kind of request with no way to narrow it, and each
  // had to be decided one at a time (FRONTEND_REDESIGN_PLAN.md, Phase 4).
  const filters = useQueryFilters({ type: "all" }, { type: ["all", ...TYPES] });
  const typeFilter = filters.values.type;

  /** Ticked requests, by public id. */
  const [selected, setSelected] = useState<Set<string>>(new Set());

  /**
   * The requests awaiting a rejection reason: one from its row, or every
   * ticked one. ReasonDialog holds the reason, and a batch shares it.
   */
  const [rejecting, setRejecting] = useState<PendingApproval[] | null>(null);

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

  function approve(targets: PendingApproval[]) {
    submit(
      {
        actions: targets.map((item) => ({
          type: item.type,
          public_id: item.public_id,
          action: "approve" as const,
        })),
      },
      { onSettled: () => setSelected(new Set()) },
    );
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
        actions: rejecting.map((item) => ({
          type: item.type,
          public_id: item.public_id,
          action: "reject" as const,
          reason,
        })),
      },
      {
        onSettled: () => {
          setRejecting(null);
          setSelected(new Set());
        },
      },
    );
  }

  const allItems = pending.data?.items ?? [];
  const items =
    typeFilter === "all"
      ? allItems
      : allItems.filter((item) => item.type === typeFilter);
  // Only what is on screen can be acted on: a ticked request the filter now
  // hides, or one someone else decided, is not sent.
  const chosen = items.filter((item) => selected.has(item.public_id));
  const allChosen = items.length > 0 && chosen.length === items.length;

  function toggle(publicId: string, on: boolean) {
    setSelected((current) => {
      const next = new Set(current);
      if (on) next.add(publicId);
      else next.delete(publicId);
      return next;
    });
  }

  return (
    <RoleGate minRole="supervisor">
      <div className="space-y-6">
        <FilterBar
          activeCount={filters.activeCount}
          onClear={() => {
            filters.clear();
            setSelected(new Set());
          }}
        >
          <Select
            value={typeFilter}
            onValueChange={(v) => {
              filters.set({ type: v });
              setSelected(new Set());
            }}
          >
            <SelectTrigger
              className="w-full sm:w-52"
              aria-label={t("approvals.filter_type", "Request type")}
            >
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="all">
                {t("approvals.filter_all", "All requests")}
              </SelectItem>
              {TYPES.map((type) => (
                <SelectItem key={type} value={type}>
                  {t(`status.${type}`, type)}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </FilterBar>

        {chosen.length > 0 && (
          <div
            role="region"
            aria-label={t("approvals.bulk_actions", "Selected requests")}
            className="flex flex-wrap items-center gap-2 rounded-lg border border-border bg-muted/40 px-3 py-2"
          >
            <span className="text-sm font-medium text-foreground">
              {t("approvals.count_selected", ":count selected", {
                count: chosen.length,
              })}
            </span>
            <Button
              size="sm"
              variant="outline"
              className="text-success-on-soft hover:bg-success-soft"
              onClick={() => approve(chosen)}
              disabled={batchAction.isPending}
            >
              <Check className="mr-1 h-3 w-3" aria-hidden="true" />
              {t("approvals.approve_selected", "Approve :count", {
                count: chosen.length,
              })}
            </Button>
            <Button
              size="sm"
              variant="outline"
              className="text-destructive hover:bg-destructive/10"
              onClick={() => setRejecting(chosen)}
              disabled={batchAction.isPending}
            >
              <X className="mr-1 h-3 w-3" aria-hidden="true" />
              {t("approvals.reject_selected", "Reject :count", {
                count: chosen.length,
              })}
            </Button>
            <Button
              size="sm"
              variant="ghost"
              onClick={() => setSelected(new Set())}
            >
              {t("approvals.clear_selection", "Clear selection")}
            </Button>
          </div>
        )}

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
          isEmpty={() => items.length === 0}
          empty={
            <EmptyState
              icon={CheckSquare}
              title={t("approvals.empty_title", "No pending approvals")}
              description={
                typeFilter !== "all" && allItems.length > 0
                  ? t(
                      "approvals.empty_filtered",
                      "Nothing of this type is waiting. Clear the filter to see the rest.",
                    )
                  : t(
                      "approvals.empty_desc",
                      "No pending approvals at this time",
                    )
              }
            />
          }
        >
          {() => (
            // One list with a row per request, where each was its own card:
            // a queue reads as a queue, and the actions line up.
            <ul className="divide-y divide-border overflow-hidden rounded-xl border border-border bg-card shadow-xs">
              <li className="flex items-center gap-3 bg-muted/40 px-5 py-2">
                <Checkbox
                  id="approvals-select-all"
                  checked={
                    allChosen
                      ? true
                      : chosen.length > 0
                        ? "indeterminate"
                        : false
                  }
                  onCheckedChange={(on) =>
                    setSelected(
                      on === true
                        ? new Set(items.map((item) => item.public_id))
                        : new Set(),
                    )
                  }
                />
                <label
                  htmlFor="approvals-select-all"
                  className="text-xs font-medium text-muted-foreground"
                >
                  {t("approvals.select_all", "Select all :count", {
                    count: items.length,
                  })}
                </label>
              </li>
              {items.map((item) => (
                <li
                  key={item.public_id}
                  className="flex flex-col gap-3 px-5 py-4 transition-colors hover:bg-muted/30 sm:flex-row sm:items-center sm:justify-between"
                >
                  <div className="flex min-w-0 items-start gap-3">
                    <Checkbox
                      className="mt-2.5"
                      checked={selected.has(item.public_id)}
                      onCheckedChange={(on) =>
                        toggle(item.public_id, on === true)
                      }
                      aria-label={t(
                        "approvals.select_item",
                        "Select the request from :name",
                        { name: item.employee_name ?? "" },
                      )}
                    />
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
                      onClick={() => approve([item])}
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
                      onClick={() => setRejecting([item])}
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
            rejecting && rejecting.length > 1
              ? t(
                  "approvals.reject_many_description",
                  "These :count requests will be rejected with the same reason, shown to each employee.",
                  { count: rejecting.length },
                )
              : rejecting
                ? t(
                    "approvals.reject_description",
                    "This will be recorded and shown to :name. Explain why so they know what to do next.",
                    { name: rejecting[0]?.employee_name ?? "" },
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
