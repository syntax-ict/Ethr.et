"use client";

import { Button } from "@/components/ui/button";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import type { BulkUpdateRun } from "@/features/employees/api";
import { useT } from "@/lib/i18n/useT";

/**
 * What a bulk update did to each person who did not simply change: the ones
 * it refused, with the reason, the ones who already had it, and the ones whose
 * sign-in role is waiting on the Users page because a new job title gives a
 * role the person making the change could not give by hand. Everyone who
 * changed plainly is only counted.
 */
export function BulkResultDialog({
  result,
  onClose,
}: {
  result: BulkUpdateRun;
  onClose: () => void;
}) {
  const { t } = useT();

  const unchanged = result.results.filter((r) => r.outcome === "unchanged");
  const failed = result.results.filter((r) => r.outcome === "failed");
  const held = result.results.filter(
    (r) => r.outcome === "updated" && r.access_held,
  );
  const rows = [
    ...failed.map((r) => ({
      ...r,
      note: r.error ?? t("employees.bulk.result.failed", "Not changed"),
    })),
    ...held.map((r) => ({
      ...r,
      note: t(
        "employees.bulk.result.access_held",
        "Changed. Their sign-in role waits on the Users page.",
      ),
    })),
    ...unchanged.map((r) => ({
      ...r,
      note: t("employees.bulk.result.unchanged", "Already as asked"),
    })),
  ];

  return (
    <Dialog open onOpenChange={(open) => !open && onClose()}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>
            {t("employees.bulk.result.title", "What happened")}
          </DialogTitle>
          <DialogDescription>
            {t(
              "employees.bulk.result.summary",
              ":updated changed, :unchanged already as asked, :failed not changed.",
              {
                updated: result.updated,
                unchanged: unchanged.length,
                failed: failed.length,
              },
            )}
          </DialogDescription>
        </DialogHeader>

        {result.unsent.length > 0 && (
          <p role="alert" className="text-sm text-destructive">
            {t(
              "employees.bulk.result.unsent",
              ":count people were not sent because a request failed. Select them and try again.",
              { count: result.unsent.length },
            )}
          </p>
        )}

        <ul className="max-h-72 space-y-2 overflow-y-auto text-sm">
          {rows.map((r) => (
            <li
              key={`${r.employee_id}-${r.outcome}`}
              className="rounded-md border border-border/60 px-3 py-2"
            >
              <span className="font-medium text-foreground">
                {r.name ?? r.employee_id}
              </span>
              <span className="block text-muted-foreground">{r.note}</span>
            </li>
          ))}
        </ul>

        <DialogFooter>
          <Button onClick={onClose}>{t("common.close", "Close")}</Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
