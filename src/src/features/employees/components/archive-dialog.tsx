"use client";

import { useState } from "react";
import { Loader2 } from "lucide-react";
import { toast } from "sonner";
import { useQuery, useQueryClient } from "@tanstack/react-query";
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
import { QueryBoundary } from "@/components/patterns/QueryBoundary";
import {
  ARCHIVE_CONFIRM_SLICE,
  ARCHIVE_SLICE,
  combineArchive,
  postBulkArchive,
  type BulkArchiveResult,
} from "@/features/employees/api";
import { sendInSlices } from "@/features/employees/send-in-slices";
import { useArchiveReasonText } from "@/features/employees/use-archive-reasons";
import { apiErrorMessage } from "@/lib/api/error-message";
import { useT } from "@/lib/i18n/useT";

interface Finished {
  result: BulkArchiveResult;
  /** People in slices that were never sent, because an earlier slice failed. */
  unsent: number;
}

/**
 * "Archive", and the dialog it opens.
 *
 * Opening it only reads: the server says who of the selection would be
 * archived and who has history and is kept, and writes nothing. The one
 * request that changes anything is the destructive button in the footer, which
 * is the confirmation. A long selection goes in slices of what one request
 * takes, and the answers are shown as one.
 *
 * Mounted only while open, so the preview is asked afresh each time and a
 * stale answer can never sit under an enabled confirm button.
 */
export function ArchiveDialog({
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

  const preview = useQuery({
    queryKey: ["employees", "archive-preview", selectedIds],
    queryFn: async () => {
      const run = await sendInSlices(
        selectedIds,
        (slice) => postBulkArchive(slice, false),
        ARCHIVE_SLICE,
      );
      if (run.error) throw run.error;
      return combineArchive(run.results);
    },
    enabled: finished === null,
    gcTime: 0,
    staleTime: 0,
    retry: false,
  });
  const shown = preview.data;

  function onOpenChange(next: boolean) {
    // Closing mid-run would hide the only sign that people are being archived.
    if (!next && !running) onClose(finished !== null);
  }

  async function run() {
    setRunning(true);
    const sent = await sendInSlices(
      selectedIds,
      (slice) => postBulkArchive(slice, true),
      ARCHIVE_CONFIRM_SLICE,
    );
    setRunning(false);
    // The list, not this dialog's own preview: that answer is done with.
    void queryClient.invalidateQueries({
      queryKey: ["employees"],
      predicate: (query) => query.queryKey[1] !== "archive-preview",
    });

    if (sent.error) {
      toast.error(
        apiErrorMessage(
          sent.error,
          t(
            "employees.archive.failed",
            "The archive stopped. Some people may already be archived.",
          ),
        ),
      );
    }
    setFinished({
      result: combineArchive(sent.results),
      unsent: sent.unsent.length,
    });
  }

  const cannotArchive =
    !shown || shown.archive_count === 0 || preview.isFetching;

  return (
    <Dialog open onOpenChange={onOpenChange}>
      <DialogContent className="max-w-3xl">
        <DialogHeader>
          <DialogTitle>
            {finished
              ? t("employees.archive.done_title", "Archive finished")
              : t("employees.archive.title", "Archive people")}
          </DialogTitle>
          <DialogDescription>
            {t(
              "employees.archive.desc",
              "Archiving is for records added by mistake, such as a person added twice. Someone who has left should be given a leaving status instead, which keeps their record. An archived record can be restored from the Archived view.",
            )}
          </DialogDescription>
        </DialogHeader>

        {finished ? (
          <FinishedBody finished={finished} />
        ) : (
          <QueryBoundary
            query={preview}
            isEmpty={() => false}
            loading={
              <p
                role="status"
                className="flex items-center gap-2 py-6 text-sm text-muted-foreground"
              >
                <Loader2 className="h-4 w-4 animate-spin" aria-hidden="true" />
                {t("employees.archive.loading", "Checking who can be archived")}
              </p>
            }
          >
            {(data) => <PreviewBody preview={data} />}
          </QueryBoundary>
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
              <Button
                variant="destructive"
                onClick={() => void run()}
                disabled={cannotArchive || running}
              >
                {running ? (
                  <Loader2
                    className="mr-2 h-4 w-4 animate-spin"
                    aria-hidden="true"
                  />
                ) : null}
                {running
                  ? t("employees.archive.archiving", "Archiving")
                  : shown?.archive_count === 1
                    ? t("employees.archive.confirm_one", "Archive 1 person")
                    : t("employees.archive.confirm", "Archive :count people", {
                        count: shown?.archive_count ?? 0,
                      })}
              </Button>
            </>
          )}
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

function PreviewBody({ preview }: { preview: BulkArchiveResult }) {
  const { t } = useT();
  const kept = preview.results.filter((r) => r.outcome === "keep");

  return (
    <div className="min-w-0 space-y-4">
      <p className="text-sm font-medium text-foreground">
        {preview.archive_count === 0
          ? t(
              "employees.archive.none",
              "Nobody in this selection can be archived.",
            )
          : preview.archive_count === 1
            ? t("employees.archive.will_one", "1 person will be archived.")
            : t("employees.archive.will", ":count people will be archived.", {
                count: preview.archive_count,
              })}
      </p>

      {preview.already_archived > 0 && (
        <p className="text-sm text-muted-foreground">
          {t(
            "employees.archive.already",
            "Already archived, so left as they are: :count.",
            { count: preview.already_archived },
          )}
        </p>
      )}

      {kept.length > 0 && (
        <section className="space-y-2">
          <h3 className="text-sm font-semibold text-foreground">
            {t("employees.archive.keep_heading", "These people will be kept")}
          </h3>
          <p className="text-sm text-muted-foreground">
            {t(
              "employees.archive.keep_hint",
              "They have history, so they leave through a status change instead.",
            )}
          </p>
          <KeptTable people={kept} />
        </section>
      )}
    </div>
  );
}

function FinishedBody({ finished }: { finished: Finished }) {
  const { t } = useT();
  const { result } = finished;
  const kept = result.results.filter((r) => r.outcome === "kept");
  const failed = result.results.filter((r) => r.outcome === "failed");

  return (
    <div className="min-w-0 space-y-4" role="status">
      <p className="text-sm font-medium text-foreground">
        {result.archive_count === 1
          ? t("employees.archive.done_one", "1 person was archived.")
          : t("employees.archive.done", ":count people were archived.", {
              count: result.archive_count,
            })}
      </p>

      {result.already_archived > 0 && (
        <p className="text-sm text-muted-foreground">
          {t(
            "employees.archive.already",
            "Already archived, so left as they are: :count.",
            { count: result.already_archived },
          )}
        </p>
      )}

      {finished.unsent > 0 && (
        <p className="text-sm text-warning-on-soft">
          {t(
            "employees.archive.unsent",
            "The request stopped before :count people were sent. Nothing was done for them. Try again.",
            { count: finished.unsent },
          )}
        </p>
      )}

      {kept.length > 0 && (
        <section className="space-y-2">
          <h3 className="text-sm font-semibold text-foreground">
            {t("employees.archive.kept_heading", "These people were kept")}
          </h3>
          <KeptTable people={kept} />
        </section>
      )}

      {failed.length > 0 && (
        <section className="space-y-2">
          <h3 className="text-sm font-semibold text-foreground">
            {t(
              "employees.archive.failed_heading",
              "These people could not be archived",
            )}
          </h3>
          <div className="max-h-60 overflow-auto rounded-md border">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>{t("migration_undo.col_name", "Name")}</TableHead>
                  <TableHead>{t("migration_undo.col_reason", "Why")}</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {failed.map((p) => (
                  <TableRow key={p.employee_id}>
                    <TableCell className="font-medium">{p.name}</TableCell>
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
  );
}

function KeptTable({ people }: { people: BulkArchiveResult["results"] }) {
  const { t } = useT();
  const reasonText = useArchiveReasonText();

  return (
    <div className="max-h-60 overflow-auto rounded-md border">
      <Table>
        <TableHeader>
          <TableRow>
            <TableHead>{t("migration_undo.col_name", "Name")}</TableHead>
            <TableHead>{t("migration_undo.col_reason", "Why")}</TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          {people.map((p) => (
            <TableRow key={p.employee_id}>
              <TableCell className="font-medium">{p.name}</TableCell>
              <TableCell className="text-muted-foreground">
                {reasonText(p.reasons)}
              </TableCell>
            </TableRow>
          ))}
        </TableBody>
      </Table>
    </div>
  );
}
