"use client";

import { useState } from "react";
import { Building2, GitBranch, Loader2, UserCog } from "lucide-react";
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

type BulkField = "department_id" | "branch_id" | "status";

// Mirrors App\Enums\EmployeeStatus. Bulk update writes the raw enum value
// (unlike the single-employee transition endpoint, it does not enforce
// canTransitionTo) so every case is offered here, matching what the backend
// actually accepts.
const STATUS_OPTIONS = [
  "hired",
  "probation",
  "confirmed",
  "suspended",
  "resigned",
  "terminated",
  "retired",
] as const;

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
  const { data: departments } = departmentsApi.useList();
  const { data: branches } = branchesApi.useList();
  const bulkUpdate = useBulkUpdateEmployees();

  function openDialog(next: BulkField) {
    setField(next);
    setValue("");
  }

  function closeDialog() {
    setField(null);
    setValue("");
  }

  function confirm() {
    if (!field || !value) return;
    bulkUpdate.mutate(
      { employee_ids: selectedIds, [field]: value },
      {
        onSuccess: (data) => {
          toast.success(
            t("employees.bulk.updated", "Updated :count employees", {
              count: data.updated,
            }),
          );
          closeDialog();
          onComplete();
        },
        onError: () =>
          toast.error(t("employees.bulk.failed", "Bulk update failed")),
      },
    );
  }

  return (
    <>
      <div className="flex flex-wrap items-center gap-2 rounded-lg border border-border/60 bg-muted/40 px-3 py-2">
        <span className="text-sm font-medium text-foreground">
          {selectedIds.length} {t("table.rows_selected", "selected")}
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

          <DialogFooter>
            <Button variant="outline" onClick={closeDialog}>
              {t("common.cancel", "Cancel")}
            </Button>
            <Button onClick={confirm} disabled={!value || bulkUpdate.isPending}>
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
