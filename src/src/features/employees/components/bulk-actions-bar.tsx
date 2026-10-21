"use client";

import { useState } from "react";
import { Building2, GitBranch, Loader2, UserCog } from "lucide-react";
import { toast } from "sonner";
import { Button } from "@/components/ui/button";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import { DualCalendarDateInput } from "@/components/shared/dual-calendar-date-input";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { branchesApi, departmentsApi } from "@/features/organization/api";
import { useBulkUpdateEmployees } from "@/features/employees/api";
import { useT } from "@/lib/i18n/useT";
import { apiErrorMessage } from "@/lib/api/error-message";

type BulkField = "department_id" | "branch_id" | "status";

// Mirrors App\Enums\EmployeeStatus. Every case is offered because a selection
// can mix statuses: the backend applies the transition rules per employee and
// reports the ones it refused, so the choice is checked there, not here.
const STATUS_OPTIONS = [
  "hired",
  "probation",
  "confirmed",
  "suspended",
  "resigned",
  "terminated",
  "retired",
] as const;

/**
 * Statuses that take someone off the active workforce. Each is recorded with
 * the reason in the employee's history, as a single change from the lifecycle
 * tab is; the bulk dialog sent none, so a batch of terminations carried no
 * reason and the date of the click (FRONTEND_REDESIGN_PLAN.md, Phase 4).
 */
const NEEDS_REASON: readonly string[] = [
  "suspended",
  "resigned",
  "terminated",
  "retired",
];

function today(): string {
  return new Date().toISOString().split("T")[0];
}

interface BulkActionsBarProps {
  selectedIds: string[];
  /** Called after a bulk action succeeds, so the caller can clear selection. */
  onComplete: () => void;
}

export function BulkActionsBar({
  selectedIds,
  onComplete,
}: BulkActionsBarProps) {
  const { t } = useT();
  const [field, setField] = useState<BulkField | null>(null);
  const [value, setValue] = useState("");
  const [effectiveDate, setEffectiveDate] = useState(today);
  const [reason, setReason] = useState("");
  const reasonRequired = field === "status" && NEEDS_REASON.includes(value);
  const { data: departments } = departmentsApi.useList();
  const { data: branches } = branchesApi.useList();
  const bulkUpdate = useBulkUpdateEmployees();

  function openDialog(next: BulkField) {
    setField(next);
    setValue("");
    setEffectiveDate(today());
    setReason("");
  }

  function closeDialog() {
    setField(null);
    setValue("");
    setReason("");
  }

  function confirm() {
    if (!field || !value) return;
    if (reasonRequired && !reason.trim()) return;
    bulkUpdate.mutate(
      field === "status"
        ? {
            employee_ids: selectedIds,
            status: value,
            effective_date: effectiveDate,
            reason: reason.trim() || undefined,
          }
        : { employee_ids: selectedIds, [field]: value },
      {
        onSuccess: (data) => {
          const refused = data.results.filter((r) => r.outcome === "failed");
          if (refused.length > 0) {
            const reasons = [
              ...new Set(refused.map((r) => r.error).filter(Boolean)),
            ];
            toast.warning(
              t(
                "employees.bulk.partial",
                "Updated :updated employees. :failed could not be changed.",
                { updated: data.updated, failed: refused.length },
              ),
              { description: reasons.join(" ") },
            );
          } else {
            toast.success(
              t("employees.bulk.updated", "Updated :count employees", {
                count: data.updated,
              }),
            );
          }
          closeDialog();
          onComplete();
        },
        onError: (err) =>
          toast.error(
            apiErrorMessage(
              err,
              t("employees.bulk.failed", "Bulk update failed"),
            ),
          ),
      },
    );
  }

  return (
    <>
      <div className="flex flex-wrap items-center gap-2 rounded-lg border border-border/60 bg-muted/40 px-3 py-2">
        <span className="text-sm font-medium text-foreground">
          {t("table.count_selected", ":count selected", {
            count: selectedIds.length,
          })}
        </span>
        <div className="flex flex-wrap items-center gap-2">
          <Button
            variant="outline"
            size="sm"
            onClick={() => openDialog("department_id")}
          >
            <Building2 className="mr-2 h-3.5 w-3.5" />
            {t("employees.bulk.change_department", "Change department")}
          </Button>
          <Button
            variant="outline"
            size="sm"
            onClick={() => openDialog("branch_id")}
          >
            <GitBranch className="mr-2 h-3.5 w-3.5" />
            {t("employees.bulk.change_branch", "Change branch")}
          </Button>
          <Button
            variant="outline"
            size="sm"
            onClick={() => openDialog("status")}
          >
            <UserCog className="mr-2 h-3.5 w-3.5" />
            {t("employees.bulk.change_status", "Change status")}
          </Button>
        </div>
      </div>

      <Dialog
        open={field !== null}
        onOpenChange={(open) => !open && closeDialog()}
      >
        <DialogContent>
          <DialogHeader>
            <DialogTitle>
              {field === "department_id" &&
                t("employees.bulk.change_department", "Change department")}
              {field === "branch_id" &&
                t("employees.bulk.change_branch", "Change branch")}
              {field === "status" &&
                t("employees.bulk.change_status", "Change status")}
            </DialogTitle>
            <DialogDescription>
              {t(
                "employees.bulk.affects_count",
                "This will update :count selected employees.",
                { count: selectedIds.length },
              )}
              {field === "status" &&
                ` ${t(
                  "employees.bulk.status_rules",
                  "Anyone who cannot move to the chosen status keeps their current one.",
                )}`}
            </DialogDescription>
          </DialogHeader>

          <Select value={value} onValueChange={setValue}>
            <SelectTrigger>
              <SelectValue
                placeholder={t("employees.bulk.select_value", "Select a value")}
              />
            </SelectTrigger>
            <SelectContent>
              {field === "department_id" &&
                departments?.data?.map((d) => (
                  <SelectItem key={d.public_id} value={d.public_id}>
                    {d.name}
                  </SelectItem>
                ))}
              {field === "branch_id" &&
                branches?.data?.map((b) => (
                  <SelectItem key={b.public_id} value={b.public_id}>
                    {b.name}
                  </SelectItem>
                ))}
              {field === "status" &&
                STATUS_OPTIONS.map((s) => (
                  <SelectItem key={s} value={s}>
                    {t(`status.${s}`, s)}
                  </SelectItem>
                ))}
            </SelectContent>
          </Select>

          {field === "status" && (
            <div className="space-y-3">
              <div>
                <Label htmlFor="bulk_effective_date" className="text-xs">
                  {t("employee.lifecycle.effective_date", "Effective Date")}
                </Label>
                <DualCalendarDateInput
                  id="bulk_effective_date"
                  value={effectiveDate}
                  onChange={setEffectiveDate}
                  required
                  className="mt-1"
                />
              </div>
              <div>
                <Label htmlFor="bulk_reason" className="text-xs">
                  {t("employee.lifecycle.reason", "Reason")}
                  {reasonRequired && (
                    <span aria-hidden="true" className="text-destructive">
                      {" "}
                      *
                    </span>
                  )}
                </Label>
                <Textarea
                  id="bulk_reason"
                  value={reason}
                  onChange={(e) => setReason(e.target.value)}
                  required={reasonRequired}
                  maxLength={1000}
                  rows={3}
                  className="mt-1"
                />
                <p className="mt-1 text-xs text-muted-foreground">
                  {reasonRequired
                    ? t(
                        "employees.bulk.reason_required_hint",
                        "Recorded in each employee's history.",
                      )
                    : t("common.optional", "Optional")}
                </p>
              </div>
            </div>
          )}

          <DialogFooter>
            <Button variant="outline" onClick={closeDialog}>
              {t("common.cancel", "Cancel")}
            </Button>
            <Button
              onClick={confirm}
              disabled={
                !value ||
                (reasonRequired && !reason.trim()) ||
                (field === "status" && !effectiveDate) ||
                bulkUpdate.isPending
              }
            >
              {bulkUpdate.isPending && (
                <Loader2 className="mr-2 h-3.5 w-3.5 animate-spin" />
              )}
              {t("common.confirm", "Confirm")}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </>
  );
}
