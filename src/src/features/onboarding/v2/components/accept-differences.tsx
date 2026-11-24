"use client";

import { useState } from "react";
import { Loader2 } from "lucide-react";
import { toast } from "sonner";
import { Button } from "@/components/ui/button";
import { Checkbox } from "@/components/ui/checkbox";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { apiErrorMessage } from "@/lib/api/error-message";
import { useT } from "@/lib/i18n/useT";
import { columnLabel } from "@/features/employees/employee-columns";
import { AcceptStalled, useAcceptDifferences } from "../api";
import type { AcceptDifferencesResult, MigrationBatch } from "../types";

/**
 * Fields that start unticked: who a person is (name, Amharic name, employee
 * number, national ID, date of birth, hire date) and what is hidden and
 * paid (TIN, salary). A list whose numbers are shifted by one line matches
 * every row to the wrong person, and accepting these would put one person's
 * details on another. They are accepted only when someone ticks them.
 */
export const UNTICKED_BY_DEFAULT = [
  "name",
  "name_am",
  "employee_code",
  "national_id",
  "date_of_birth",
  "hire_date",
  "tin",
  "salary_cents",
];

/** A person's job is changed with a personnel action, never from a list. */
const JOB = [
  "position",
  "branch",
  "grade",
  "cost_center",
  "probation_end_date",
];

/** A list that completes people never changes a department or a salary. */
const LOCKED_WHEN_COMPLETING = ["department", "salary_cents"];

/** The row staging marked as possibly someone else. */
const MAYBE_OTHER_PERSON = "maybe_other_person";

type ReviewBatch = Pick<MigrationBatch, "status" | "mode" | "rows">;

type Open = {
  /** field => people whose value would change */
  counts: Map<string, number>;
  /** rows that may be someone else and have a difference to accept */
  held: number;
};

/**
 * What the rows on the page would take, counted from the rows the page
 * already holds: a conflict that is not taken, not yet accepted, on a row
 * set to merge into a person the viewer may edit.
 */
function openDifferences(batch: ReviewBatch): Open {
  const completing = batch.mode === "complete";
  const counts = new Map<string, number>();
  let held = 0;

  for (const row of batch.rows) {
    if (
      row.action !== "merge" ||
      row.processed_at ||
      !row.resolved_employee_public_id ||
      !row.can_apply_changes
    ) {
      continue;
    }

    const fields = row.changes
      .filter(
        (change) =>
          change.kind === "conflict" &&
          !change.taken &&
          !change.accepted &&
          !JOB.includes(change.field) &&
          !(completing && LOCKED_WHEN_COMPLETING.includes(change.field)),
      )
      .map((change) => change.field);

    if (fields.length === 0) continue;

    if ((row.warnings ?? []).some((w) => w.code === MAYBE_OTHER_PERSON)) {
      held += 1;
      continue;
    }

    for (const field of fields) {
      counts.set(field, (counts.get(field) ?? 0) + 1);
    }
  }

  return { counts, held };
}

/**
 * "Accept differences": take the same fields from the list for many people
 * at once, instead of ticking each row. Shown while the list is in review
 * and at least one row to merge has a difference to take.
 *
 * Nothing is written to anyone here: it marks differences as accepted, as
 * ticking them on a row does, and the list still writes them only when it is
 * added and only while both values are still the ones shown.
 */
export function AcceptDifferences({
  batchId,
  batch,
  onDone,
}: {
  batchId: string | null;
  batch: ReviewBatch;
  onDone?: () => void;
}) {
  const { t } = useT();
  const [open, setOpen] = useState(false);

  if (!batchId || batch.status !== "reviewing") return null;

  const { counts, held } = openDifferences(batch);
  if (counts.size === 0) return null;

  return (
    <>
      <Button
        type="button"
        variant="outline"
        size="sm"
        onClick={() => setOpen(true)}
      >
        {t("accept_differences.button", "Accept differences")}
      </Button>
      {open && (
        <AcceptDialog
          batchId={batchId}
          counts={counts}
          held={held}
          onClose={() => setOpen(false)}
          onDone={onDone}
        />
      )}
    </>
  );
}

function AcceptDialog({
  batchId,
  counts,
  held,
  onClose,
  onDone,
}: {
  batchId: string;
  counts: Map<string, number>;
  held: number;
  onClose: () => void;
  onDone?: () => void;
}) {
  const { t } = useT();
  const accept = useAcceptDifferences();
  const fields = [...counts.keys()];
  const [ticked, setTicked] = useState<string[]>(() =>
    fields.filter((field) => !UNTICKED_BY_DEFAULT.includes(field)),
  );

  function people(count: number): string {
    return count === 1
      ? t("accept_differences.people_one", "1 person")
      : t("accept_differences.people", ":count people", { count });
  }

  function toggle(field: string, on: boolean) {
    setTicked((now) =>
      on
        ? [...now.filter((f) => f !== field), field]
        : now.filter((f) => f !== field),
    );
  }

  function resultLine(result: AcceptDifferencesResult): string {
    const counts = {
      accepted: result.rows,
      out_of_reach: result.out_of_reach,
      held: result.held,
      pay_refused: result.pay_refused,
    };

    return result.pay_refused > 0
      ? t(
          "accept_differences.result_pay",
          "Accepted for :accepted people. :out_of_reach you may not edit. :held left for you. Salary and TIN were left for :pay_refused people, because you may not change pay.",
          counts,
        )
      : t(
          "accept_differences.result",
          "Accepted for :accepted people. :out_of_reach you may not edit. :held left for you.",
          counts,
        );
  }

  async function run() {
    try {
      const result = await accept.mutateAsync({ batchId, fields: ticked });
      toast.success(resultLine(result));
      onDone?.();
      onClose();
    } catch (err) {
      toast.error(
        err instanceof AcceptStalled
          ? t(
              "accept_differences.failed",
              "Accepting stopped. Some differences may already be accepted. Try again.",
            )
          : apiErrorMessage(
              err,
              t(
                "accept_differences.failed",
                "Accepting stopped. Some differences may already be accepted. Try again.",
              ),
            ),
      );
      onDone?.();
    }
  }

  return (
    <Dialog
      open
      onOpenChange={(next) => {
        if (!next && !accept.isPending) onClose();
      }}
    >
      <DialogContent>
        <DialogHeader>
          <DialogTitle>
            {t(
              "accept_differences.title",
              "Accept differences for many people",
            )}
          </DialogTitle>
          <DialogDescription>
            {t(
              "accept_differences.desc",
              "Choose the details to take from this list for everyone it matched. Nothing is written to anyone until the list is added.",
            )}
          </DialogDescription>
        </DialogHeader>

        <ul className="space-y-2">
          {fields.map((field) => {
            const id = `accept-differences-${field}`;
            const careful = UNTICKED_BY_DEFAULT.includes(field);

            return (
              <li key={field} className="flex items-start gap-2 text-sm">
                <Checkbox
                  id={id}
                  checked={ticked.includes(field)}
                  disabled={accept.isPending}
                  onCheckedChange={(value) => toggle(field, value === true)}
                />
                <label htmlFor={id} className="space-y-0.5">
                  <span className="block text-foreground">
                    {columnLabel(field, t)}
                    <span className="ml-2 text-muted-foreground">
                      {people(counts.get(field) ?? 0)}
                    </span>
                  </span>
                  {careful && (
                    <span className="block text-xs text-muted-foreground">
                      {t(
                        "accept_differences.sensitive",
                        "Not ticked at first. A number shifted by a line would put one person's details on another.",
                      )}
                    </span>
                  )}
                </label>
              </li>
            );
          })}
        </ul>

        {held > 0 && (
          <p
            role="status"
            className="rounded-md border border-warning-edge bg-warning-soft px-3 py-2 text-xs text-warning-on-soft"
          >
            {held === 1
              ? t(
                  "accept_differences.held_one",
                  "1 row may be someone else. It is left for you to decide on its own row.",
                )
              : t(
                  "accept_differences.held",
                  ":count rows may be someone else. They are left for you to decide on their own rows.",
                  { count: held },
                )}
          </p>
        )}

        <DialogFooter>
          <Button
            type="button"
            variant="outline"
            disabled={accept.isPending}
            onClick={onClose}
          >
            {t("common.cancel", "Cancel")}
          </Button>
          <Button
            type="button"
            disabled={accept.isPending || ticked.length === 0}
            onClick={run}
          >
            {accept.isPending && (
              <Loader2
                className="mr-2 h-4 w-4 animate-spin"
                aria-hidden="true"
              />
            )}
            {accept.isPending
              ? t("accept_differences.running", "Accepting")
              : t("accept_differences.confirm", "Accept")}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
