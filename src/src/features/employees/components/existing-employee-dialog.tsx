"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { toast } from "sonner";
import { Loader2 } from "lucide-react";
import { useT } from "@/lib/i18n/useT";
import { usePermissions } from "@/lib/hooks/usePermissions";
import { toastError } from "@/lib/errors";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { useRestoreEmployee } from "@/features/employees/api";
import {
  matchReasonLabel,
  type EmployeeLikeness,
} from "@/features/employees/likeness";

/**
 * Shown when adding an employee is answered "this looks like someone you
 * already have". One employee record per person: a second one splits their
 * attendance and pay.
 *
 * A certain match can only be opened. A likeness can be overruled by the
 * person adding ("Add as a new person"), which sends the same form again with
 * `confirm_new`. A deleted record can be restored by someone who may delete.
 */
export function ExistingEmployeeDialog({
  likeness,
  ...rest
}: {
  likeness: EmployeeLikeness | null;
  /** The confirmed save is in flight. */
  adding: boolean;
  onAddAnyway: () => void;
  onClose: () => void;
}) {
  // Nothing is read, and no permission is asked for, until there is a
  // likeness to show.
  return likeness ? <LikenessDialog likeness={likeness} {...rest} /> : null;
}

function LikenessDialog({
  likeness,
  adding,
  onAddAnyway,
  onClose,
}: {
  likeness: EmployeeLikeness;
  adding: boolean;
  onAddAnyway: () => void;
  onClose: () => void;
}) {
  const { t } = useT();
  const router = useRouter();
  const { can } = usePermissions();
  const restore = useRestoreEmployee();

  const confirmable = likeness.code === "employee-needs-confirmation";
  const archived = likeness.outcome === "archived";
  const first = likeness.candidates[0];
  const busy = adding || restore.isPending;

  function handleRestore() {
    if (!first) return;
    restore.mutate(first.public_id, {
      onSuccess: () => {
        toast.success(t("employee.likeness.restored", "Record restored"));
        onClose();
        router.push(`/employees/${first.public_id}`);
      },
      onError: (err: unknown) =>
        toastError(
          err,
          t(
            "employee.likeness.restore_failed",
            "The record could not be restored",
          ),
        ),
    });
  }

  return (
    <Dialog open onOpenChange={(open) => !open && !busy && onClose()}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>
            {confirmable
              ? t("employee.likeness.title_confirm", "Is this someone new?")
              : t("employee.likeness.title_exists", "Already an employee")}
          </DialogTitle>
          <DialogDescription>{likeness.detail}</DialogDescription>
        </DialogHeader>

        <ul className="space-y-2">
          {likeness.candidates.map((candidate) => (
            <li
              key={candidate.public_id}
              className="flex flex-wrap items-center gap-2 rounded-md border border-border p-3 text-sm"
            >
              {archived ? (
                <span className="font-medium">{candidate.name}</span>
              ) : (
                <Link
                  href={`/employees/${candidate.public_id}`}
                  className="font-medium text-primary underline-offset-4 hover:underline"
                >
                  {candidate.name}
                </Link>
              )}
              {candidate.reasons.map((reason) => (
                <Badge key={reason} variant="secondary">
                  {matchReasonLabel(reason, t)}
                </Badge>
              ))}
            </li>
          ))}
        </ul>

        {archived && !can.deleteEmployees && (
          <p className="text-sm text-muted-foreground">
            {t(
              "employee.likeness.restore_needs_admin",
              "Someone who may delete employees can restore this record.",
            )}
          </p>
        )}

        <DialogFooter>
          <Button
            type="button"
            variant="outline"
            onClick={onClose}
            disabled={busy}
          >
            {confirmable
              ? t("common.cancel", "Cancel")
              : t("common.close", "Close")}
          </Button>

          {archived && can.deleteEmployees && first && (
            <Button
              type="button"
              variant="outline"
              onClick={handleRestore}
              disabled={busy}
            >
              {restore.isPending && (
                <Loader2 className="mr-2 h-4 w-4 animate-spin" />
              )}
              {t("employee.likeness.restore", "Restore their record")}
            </Button>
          )}

          {confirmable ? (
            <Button type="button" onClick={onAddAnyway} disabled={busy}>
              {adding && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
              {t("employee.likeness.add_anyway", "Add as a new person")}
            </Button>
          ) : (
            first && (
              <Button asChild>
                <Link href={`/employees/${first.public_id}`}>
                  {t("employee.likeness.open_record", "Open their record")}
                </Link>
              </Button>
            )
          )}
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
