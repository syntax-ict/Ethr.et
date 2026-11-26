"use client";

import { useState } from "react";
import { Loader2 } from "lucide-react";
import { toast } from "sonner";
import { useQueryClient } from "@tanstack/react-query";
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
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table";
import {
  ARCHIVE_SLICE,
  combineRestore,
  postBulkRestore,
  type BulkRestoreResult,
} from "@/features/employees/api";
import { sendInSlices } from "@/features/employees/send-in-slices";
import { apiErrorMessage } from "@/lib/api/error-message";
import { useT } from "@/lib/i18n/useT";

interface Finished {
  result: BulkRestoreResult;
  /** People in slices that were never sent, because an earlier slice failed. */
  unsent: number;
}

/**
 * "Restore", for the people the Archived view has selected. The server brings
 * each back as the single restore does, and says who it could not, for
 * example because the plan has no seat left; those are listed with the reason.
 */
export function RestoreDialog({
  selectedIds,
  onClose,
}: {
  selectedIds: string[];
  /** `done` is true once something was sent, so the caller can clear its selection. */
  onClose: (done: boolean) => void;
}) {
  const { t } = useT();
  const queryClient = useQueryClient();
  const [running, setRunning] = useState(false);
  const [finished, setFinished] = useState<Finished | null>(null);

  function onOpenChange(next: boolean) {
    if (!next && !running) onClose(finished !== null);
  }

  async function run() {
    setRunning(true);
    const sent = await sendInSlices(
      selectedIds,
      postBulkRestore,
      ARCHIVE_SLICE,
    );
    setRunning(false);
    void queryClient.invalidateQueries({ queryKey: ["employees"] });

    if (sent.error) {
      toast.error(
        apiErrorMessage(
          sent.error,
          t(
            "employees.restore.failed",
            "The restore stopped. Some people may already be restored.",
          ),
        ),
      );
    }
    setFinished({
      result: combineRestore(sent.results),
      unsent: sent.unsent.length,
    });
  }

  const failed = finished?.result.results.filter((r) => r.outcome === "failed");

  return (
    <Dialog open onOpenChange={onOpenChange}>
      <DialogContent className="max-w-2xl">
        <DialogHeader>
          <DialogTitle>
            {finished
              ? t("employees.restore.done_title", "Restore finished")
              : t("employees.restore.title", "Restore people")}
          </DialogTitle>
          <DialogDescription>
            {t(
              "employees.restore.desc",
              "A restored person comes back with their record and history, and takes a seat on your plan. A sign-in closed when they were archived stays closed.",
            )}
          </DialogDescription>
        </DialogHeader>

        {finished && (
          <div className="min-w-0 space-y-4" role="status">
            <p className="text-sm font-medium text-foreground">
              {finished.result.restored === 1
                ? t("employees.restore.done_one", "1 person was restored.")
                : t("employees.restore.done", ":count people were restored.", {
                    count: finished.result.restored,
                  })}
            </p>

            {finished.unsent > 0 && (
              <p className="text-sm text-warning-on-soft">
                {t(
                  "employees.restore.unsent",
                  "The request stopped before :count people were sent. Nothing was done for them. Try again.",
                  { count: finished.unsent },
                )}
              </p>
            )}

            {failed && failed.length > 0 && (
              <section className="space-y-2">
                <h3 className="text-sm font-semibold text-foreground">
                  {t(
                    "employees.restore.failed_heading",
                    "These people were not restored",
                  )}
                </h3>
                <div className="max-h-60 overflow-auto rounded-md border">
                  <Table>
                    <TableHeader>
                      <TableRow>
                        <TableHead>
                          {t("migration_undo.col_name", "Name")}
                        </TableHead>
                        <TableHead>
                          {t("migration_undo.col_reason", "Why")}
                        </TableHead>
                      </TableRow>
                    </TableHeader>
                    <TableBody>
                      {failed.map((p) => (
                        <TableRow key={p.employee_id}>
                          <TableCell className="font-medium">
                            {p.name}
                          </TableCell>
                          <TableCell className="text-muted-foreground">
                            {p.error}
                          </TableCell>
                        </TableRow>
                      ))}
                    </TableBody>
                  </Table>
                </div>
              </section>
            )}
          </div>
        )}

        <DialogFooter>
          {finished ? (
            <Button onClick={() => onClose(true)}>
              {t("common.close", "Close")}
            </Button>
          ) : (
            <>
              <Button
                variant="outline"
                onClick={() => onOpenChange(false)}
                disabled={running}
              >
                {t("common.cancel", "Cancel")}
              </Button>
              <Button onClick={() => void run()} disabled={running}>
                {running ? (
                  <Loader2
                    className="mr-2 h-4 w-4 animate-spin"
                    aria-hidden="true"
                  />
                ) : null}
                {running
                  ? t("employees.restore.restoring", "Restoring")
                  : selectedIds.length === 1
                    ? t("employees.restore.confirm_one", "Restore 1 person")
                    : t("employees.restore.confirm", "Restore :count people", {
                        count: selectedIds.length,
                      })}
              </Button>
            </>
          )}
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
