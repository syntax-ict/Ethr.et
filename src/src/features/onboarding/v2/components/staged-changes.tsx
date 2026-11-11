"use client";

import { Checkbox } from "@/components/ui/checkbox";
import { useT } from "@/lib/i18n/useT";
import type { StagedChange, StagingRow } from "../types";

type Translate = ReturnType<typeof useT>["t"];

/** Where each field's name already lives in the dictionary. */
const FIELD_LABELS: Record<string, [string, string]> = {
  name: ["setup2.col_name", "Name"],
  email: ["setup2.col_email", "Email"],
  phone: ["setup2.col_phone", "Phone"],
  employee_code: ["setup2.col_employee_code", "Employee number"],
  badge_number: ["setup2.col_badge_number", "Badge number"],
  national_id: ["employee.national_id", "National ID"],
  tin: ["employee.tin", "TIN"],
  hire_date: ["setup2.col_hire_date", "Hire date"],
  department: ["setup2.col_department", "Department"],
  salary_cents: ["employee.detail.salary", "Salary"],
};

function fieldLabel(field: string, t: Translate): string {
  const known = FIELD_LABELS[field];
  return known ? t(known[0], known[1]) : field;
}

/** One change in words. A masked field is named and its values are not. */
function describe(change: StagedChange, t: Translate): string {
  const field = fieldLabel(change.field, t);

  if (change.taken) {
    return t(
      "setup2.changes_taken",
      ":field: :value belongs to someone else and is left as it is",
      { field, value: change.incoming ?? "" },
    );
  }

  if (change.kind === "fill") {
    return change.incoming === null
      ? t("setup2.changes_fills", "Fills in :field", { field })
      : t("setup2.changes_fill_value", "Fills in :field: :value", {
          field,
          value: change.incoming,
        });
  }

  if (change.incoming === null) {
    return t(
      "setup2.changes_conflict_masked",
      ":field is different in this list",
      { field },
    );
  }

  return change.current === null
    ? t("setup2.changes_conflict_incoming", ":field: this list has :incoming", {
        field,
        incoming: change.incoming,
      })
    : t(
        "setup2.changes_conflict",
        ":field: ETHR has :current, this list has :incoming",
        { field, current: change.current, incoming: change.incoming },
      );
}

/**
 * What adding a matched row would change on the employee it matched.
 *
 * A blank in ETHR is filled without asking. A difference is written only
 * where the reviewer ticks it, and ticking is offered only to someone who may
 * edit that employee. One employee record per person: the row never becomes
 * a second one, so this is where its details go.
 */
export function StagedChanges({
  row,
  locked,
  onAccept,
}: {
  row: StagingRow;
  /** The row is written, or the list is no longer open for review. */
  locked: boolean;
  onAccept: (fields: string[]) => void;
}) {
  const { t } = useT();
  const changes = row.changes ?? [];

  // Once the row is written the list is history: "ETHR has" would no longer
  // be true of anything that was accepted.
  if (row.action !== "merge" || changes.length === 0 || row.processed_at) {
    return null;
  }

  const held = row.scheduled_action || !row.can_apply_changes;
  const accepted = changes
    .filter((change) => change.accepted)
    .map((change) => change.field);

  function toggle(field: string, on: boolean) {
    onAccept(
      on
        ? [...accepted.filter((f) => f !== field), field]
        : accepted.filter((f) => f !== field),
    );
  }

  return (
    <div className="space-y-1 border-t border-dashed pt-1">
      <p className="text-[11px] font-medium text-foreground">
        {t("setup2.changes_title", "Changes to their record")}
      </p>

      {held && (
        <p className="text-[11px] text-muted-foreground">
          {row.scheduled_action
            ? t(
                "setup2.changes_scheduled",
                "A change to this person is scheduled for a later date, so their record is left as it is.",
              )
            : t(
                "setup2.changes_not_permitted",
                "You cannot edit this person, so their record is left as it is.",
              )}
        </p>
      )}

      <ul className="space-y-1">
        {changes.map((change) => {
          const id = `${row.public_id}-${change.field}`;
          const choosable = change.kind === "conflict" && !change.taken;

          return (
            <li
              key={change.field}
              className="text-[11px] text-muted-foreground"
            >
              <span>{describe(change, t)}</span>
              {choosable && (
                <span className="ml-2 inline-flex items-center gap-1 align-middle">
                  <Checkbox
                    id={id}
                    checked={change.accepted}
                    disabled={locked || held}
                    onCheckedChange={(value) =>
                      toggle(change.field, value === true)
                    }
                  />
                  <label htmlFor={id} className="text-foreground">
                    {t("setup2.changes_accept", "Use this list's value")}
                  </label>
                </span>
              )}
            </li>
          );
        })}
      </ul>
    </div>
  );
}
