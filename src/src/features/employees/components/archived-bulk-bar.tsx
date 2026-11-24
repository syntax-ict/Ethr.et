"use client";

import { useState } from "react";
import { ArchiveRestore } from "lucide-react";
import { Button } from "@/components/ui/button";
import { RestoreDialog } from "@/features/employees/components/restore-dialog";
import { useT } from "@/lib/i18n/useT";

/**
 * The bar over a selection in the Archived view. The only thing to do with an
 * archived person is bring them back; the other bulk actions are for people
 * who are here.
 */
export function ArchivedBulkBar({
  selectedIds,
  onComplete,
}: {
  selectedIds: string[];
  /** Called when the restore dialog closes after restoring, so the caller can clear selection. */
  onComplete: () => void;
}) {
  const { t } = useT();
  const [open, setOpen] = useState(false);

  return (
    <>
      <div className="flex flex-wrap items-center gap-2 rounded-lg border border-border/60 bg-muted/40 px-3 py-2">
        <span className="text-sm font-medium text-foreground">
          {t("table.count_selected", ":count selected", {
            count: selectedIds.length,
          })}
        </span>
        <Button variant="outline" size="sm" onClick={() => setOpen(true)}>
          <ArchiveRestore className="mr-2 h-3.5 w-3.5" aria-hidden="true" />
          {t("employees.restore.button", "Restore")}
        </Button>
      </div>

      {open && (
        <RestoreDialog
          selectedIds={selectedIds}
          onClose={(done) => {
            setOpen(false);
            if (done) onComplete();
          }}
        />
      )}
    </>
  );
}
