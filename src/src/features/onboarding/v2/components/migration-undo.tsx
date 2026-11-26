"use client";

import { useState } from "react";
import { Loader2, Undo2 } from "lucide-react";
import { toast } from "sonner";
import { Alert, AlertDescription } from "@/components/ui/alert";
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
import { apiErrorMessage } from "@/lib/api/error-message";
import { useDateFormatters } from "@/lib/hooks/useTenantTimezone";
import { usePermissions } from "@/lib/hooks/usePermissions";
import { useT } from "@/lib/i18n/useT";
import { useArchiveReasonText } from "@/features/employees/use-archive-reasons";
import { UndoStalled, useUndoMigration, useUndoPreview } from "../api";
import type { MigrationBatch, UndoPerson, UndoPreview } from "../types";

type UndoBatch = Pick<
  MigrationBatch,
  "status" | "mode" | "undone_at" | "undo_archived"
>;

/**
 * A quiet line above the committed list's buttons once its additions were
 * undone. Shown to anyone who can read the list: it is a fact about the list,
 * not a way to act on it.
 */
export function MigrationUndoNote({ batch }: { batch: UndoBatch }) {
  const { t } = useT();
  const { formatDate } = useDateFormatters();

  if (batch.status !== "committed" || !batch.undone_at) return null;

  const count = batch.undo_archived ?? 0;
  const date = formatDate(batch.undone_at);

  return (
    <p className="w-full text-sm text-muted-foreground">
      {count === 1
        ? t(
            "migration_undo.undone_one",
            "This list's additions were undone on :date. 1 person was archived.",
            { date },
          )
        : t(
            "migration_undo.undone",
            "This list's additions were undone on :date. :count people were archived.",
            { date, count },
          )}
    </p>
  );
}

/**
 * "Undo this list's additions", and the dialog it opens.
 *
 * Opening the dialog only reads: the server says who the list added and who of
 * them is still unused. The one request that changes anything is the
 * destructive button in the footer, which is the confirmation. The server
 * archives (restorable) the people this list itself created and nobody else;
 * people it only matched are never touched.
 */
export function MigrationUndoButton({
  batchId,
  batch,
}: {
  batchId: string;
  batch: UndoBatch;
}) {
  const { t } = useT();
  const { can } = usePermissions();
  const [open, setOpen] = useState(false);

  if (
    batch.status !== "committed" ||
    batch.mode === "complete" ||
    !can.deleteEmployees
  ) {
    return null;
  }

  return (
    <>
      <Button variant="outline" size="sm" onClick={() => setOpen(true)}>
        <Undo2 className="mr-2 h-4 w-4" aria-hidden="true" />
        {t("migration_undo.button", "Undo this list's additions")}
      </Button>
      {/* Mounted only while open, so the preview is asked afresh each time and
          a stale answer can never sit under an enabled confirm button. */}
      {open && <UndoDialog batchId={batchId} onClose={() => setOpen(false)} />}
    </>
  );
}

function UndoDialog({
  batchId,
  onClose,
}: {
  batchId: string;
  onClose: () => void;
}) {
  const { t } = useT();
  const [remaining, setRemaining] = useState<number | null>(null);
  const preview = useUndoPreview(batchId, true);
  const undo = useUndoMigration((result) => setRemaining(result.remaining));
  const shown = preview.data;

  function onOpenChange(next: boolean) {
    // Closing mid-run would hide the only sign that people are being archived.
    if (!next && !undo.isPending) onClose();
  }

  async function run() {
    setRemaining(shown?.archive_count ?? null);
    try {
      const result = await undo.mutateAsync(batchId);
      toast.success(archivedMessage(result.archived));
      onClose();
    } catch (err) {
      toast.error(
        err instanceof UndoStalled
          ? t(
              "migration_undo.stalled",
              "The undo stopped moving. Some people may already be archived. Try again.",
            )
          : apiErrorMessage(
              err,
              t(
                "migration_undo.failed",
                "The additions could not be undone. Some people may already be archived.",
              ),
            ),
      );
      // Some people may be archived already: show who is left.
      void preview.refetch();
      setRemaining(null);
    }
  }

  function archivedMessage(count: number): string {
    return count === 1
      ? t(
          "migration_undo.archived_one",
          "1 person was archived. They are archived, not deleted.",
        )
      : t(
          "migration_undo.archived",
          ":count people were archived. They are archived, not deleted.",
          { count },
        );
  }

  const cannotArchive =
    !shown || !shown.exact || shown.archive_count === 0 || preview.isFetching;

  return (
    <Dialog open onOpenChange={onOpenChange}>
      <DialogContent className={shown ? "max-w-3xl" : undefined}>
        <DialogHeader>
          <DialogTitle>
            {t("migration_undo.title", "Undo this list's additions")}
          </DialogTitle>
          <DialogDescription>
            {t(
              "migration_undo.desc",
              "Archives the people this list added and nobody else. Anyone who has been used since is kept. An archived person can be restored.",
            )}
          </DialogDescription>
        </DialogHeader>

        <QueryBoundary
          query={preview}
          isEmpty={() => false}
          loading={
            <p
              role="status"
              className="flex items-center gap-2 py-6 text-sm text-muted-foreground"
            >
              <Loader2 className="h-4 w-4 animate-spin" aria-hidden="true" />
              {t("migration_undo.loading", "Checking who this list added")}
            </p>
          }
        >
          {(data) => <PreviewBody preview={data} />}
        </QueryBoundary>

        <DialogFooter>
          <Button
            variant="outline"
            onClick={() => onOpenChange(false)}
            disabled={undo.isPending}
          >
            {t("common.cancel", "Cancel")}
          </Button>
          <Button
            variant="destructive"
            onClick={() => void run()}
            disabled={cannotArchive || undo.isPending}
          >
            {undo.isPending ? (
              <Loader2
                className="mr-2 h-4 w-4 animate-spin"
                aria-hidden="true"
              />
            ) : null}
            {undo.isPending
              ? t("migration_undo.archiving", "Archiving, :count left", {
                  count: remaining ?? shown?.archive_count ?? 0,
                })
              : shown?.archive_count === 1
                ? t("migration_undo.confirm_one", "Archive 1 person")
                : t("migration_undo.confirm", "Archive :count people", {
                    count: shown?.archive_count ?? 0,
                  })}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

function PreviewBody({ preview }: { preview: UndoPreview }) {
  const { t } = useT();
  const keptTotal = preview.keep_count + preview.out_of_reach;
  const moreArchive = preview.archive_count - preview.archive.length;
  const moreKeep = preview.keep_count - preview.keep.length;

  // The reasons are shared with archiving a selection of people; only "not
  // certain this list added them" belongs to this screen.
  const reasonText = useArchiveReasonText({
    unsure_origin: t(
      "migration_undo.reason_unsure_origin",
      "Not certain this list added them",
    ),
  });

  return (
    <div className="min-w-0 space-y-4">
      <dl
        data-testid="undo-tiles"
        className="grid grid-cols-2 gap-3 text-sm sm:grid-cols-4"
      >
        <Tile
          label={t("migration_undo.tile_added", "Added by this list")}
          value={preview.added}
        />
        <Tile
          label={t("migration_undo.tile_archive", "Will be archived")}
          value={preview.archive_count}
        />
        <Tile
          label={t("migration_undo.tile_keep", "Will be kept")}
          value={keptTotal}
        />
        {preview.already_archived > 0 && (
          <Tile
            label={t("migration_undo.tile_already", "Already archived")}
            value={preview.already_archived}
          />
        )}
      </dl>

      {preview.matched > 0 && (
        <p className="text-sm text-muted-foreground">
          {t(
            "migration_undo.matched",
            "People this list only matched or updated are not touched: :count.",
            { count: preview.matched },
          )}
        </p>
      )}

      {preview.origin === "derived" && preview.exact && (
        <p className="text-sm text-muted-foreground">
          {t(
            "migration_undo.derived",
            "This list was added before ETHR recorded which people a list added, so they were worked out from its records.",
          )}
        </p>
      )}

      {!preview.exact && (
        <Alert variant="warning">
          <AlertDescription>
            {t(
              "migration_undo.inexact",
              "The records of this list do not agree on who it added, so nobody is archived. Each person is listed below.",
            )}
          </AlertDescription>
        </Alert>
      )}

      {preview.archive_count === 0 && (
        <p className="text-sm font-medium text-foreground">
          {t("migration_undo.none", "Nobody this list added can be archived.")}
        </p>
      )}

      {preview.archive.length > 0 && (
        <section className="space-y-2">
          <h3 className="text-sm font-semibold text-foreground">
            {t(
              "migration_undo.archive_heading",
              "These people will be archived",
            )}
          </h3>
          <PeopleTable people={preview.archive} />
          {moreArchive > 0 && <More count={moreArchive} />}
        </section>
      )}

      {preview.keep.length > 0 && (
        <section className="space-y-2">
          <h3 className="text-sm font-semibold text-foreground">
            {t("migration_undo.keep_heading", "These people will be kept")}
          </h3>
          <PeopleTable
            people={preview.keep}
            reasons={(p) => reasonText(p.reasons)}
          />
          {moreKeep > 0 && <More count={moreKeep} />}
        </section>
      )}

      {preview.out_of_reach > 0 && (
        <p className="text-sm text-muted-foreground">
          {preview.out_of_reach === 1
            ? t(
                "migration_undo.out_of_reach_one",
                "1 person is outside the people you may archive. They are kept.",
              )
            : t(
                "migration_undo.out_of_reach",
                ":count people are outside the people you may archive. They are kept.",
                { count: preview.out_of_reach },
              )}
        </p>
      )}
    </div>
  );
}

function Tile({ label, value }: { label: string; value: number }) {
  return (
    <div className="rounded-md border p-2">
      <dt className="text-xs text-muted-foreground">{label}</dt>
      <dd className="font-mono font-medium">{value}</dd>
    </div>
  );
}

function More({ count }: { count: number }) {
  const { t } = useT();
  return (
    <p className="text-xs text-muted-foreground">
      {t("migration_undo.more", "and :count more", { count })}
    </p>
  );
}

function PeopleTable<P extends UndoPerson>({
  people,
  reasons,
}: {
  people: P[];
  reasons?: (person: P) => string;
}) {
  const { t } = useT();
  return (
    <div className="max-h-60 overflow-auto rounded-md border">
      <Table>
        <TableHeader>
          <TableRow>
            <TableHead>{t("migration_undo.col_name", "Name")}</TableHead>
            <TableHead>{t("migration_undo.col_number", "Number")}</TableHead>
            {reasons && (
              <TableHead>{t("migration_undo.col_reason", "Why")}</TableHead>
            )}
          </TableRow>
        </TableHeader>
        <TableBody>
          {people.map((p) => (
            <TableRow key={p.employee_public_id}>
              <TableCell className="font-medium">{p.name}</TableCell>
              <TableCell className="font-mono text-xs">
                {p.employee_code ?? ""}
              </TableCell>
              {reasons && (
                <TableCell className="text-muted-foreground">
                  {reasons(p)}
                </TableCell>
              )}
            </TableRow>
          ))}
        </TableBody>
      </Table>
    </div>
  );
}
